<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

class PatientController
{
    private $db;

    public function __construct(SheetsDB $db)
    {
        $this->db = $db;
    }

    /** GET /api/patients */
    public function index()
    {
        Auth::authorize(['doctor', 'staff']);
        $pg     = Request::pagination();
        $search = Request::query('search');

        $patients = $search
            ? $this->db->search('patients', ['name', 'email', 'mobile'], $search)
            : $this->db->all('patients');

        // Attach diagnosis name
        $diagnoses = $this->db->all('diagnoses');
        $diagMap   = [];
        foreach ($diagnoses as $d) $diagMap[$d['id']] = $d['diagnosis_name'] ?? $d['name'] ?? '';

        foreach ($patients as &$p) {
            unset($p['password'], $p['_row']);
            $p['diagnosis_name'] = $diagMap[$p['diagnosis_id'] ?? ''] ?? '';
            $p['relatives']      = $this->getRelatives($p);
        }

        // Sort by created_at desc
        usort($patients, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        $total    = count($patients);
        $patients = array_slice($patients, $pg['offset'], $pg['per_page']);

        Response::success("Patients fetched", [
            'patients'   => array_values($patients),
            'pagination' => Request::paginated($total, $pg['page'], $pg['per_page']),
        ]);
    }

    private function getRelatives(array $patient): array
    {
        if (empty($patient['relative_id'])) return [];
        $rel = $this->db->findById('patients', $patient['relative_id']);
        if (!$rel || (!empty($rel['soft_delete']) && $rel['soft_delete'] === '1')) return [];
        return [['id' => $rel['id'], 'name' => $rel['name'], 'unique_id' => $rel['unique_id'], 'mobile' => $rel['mobile']]];
    }

    /** GET /api/patients/{id} */
    public function show($id)
    {
        Auth::authorize(['doctor', 'staff', 'user']);

        $patient = $this->db->findById('patients', $id);
        if (!$patient || (!empty($patient['soft_delete']) && $patient['soft_delete'] === '1')) {
            Response::notFound("Patient not found");
        }
        unset($patient['password'], $patient['_row']);

        // Relatives
        $relatives            = $this->getRelatives($patient);
        $patient['relatives'] = $relatives;
        $patient['total_relatives'] = count($relatives);

        // Visits
        $allVisits = $this->db->all('visits');
        $visits    = array_filter($allVisits, fn($v) => (string)$v['patient_id'] === (string)$id);
        usort($visits, fn($a, $b) => strcmp($b['visit_date'] ?? '', $a['visit_date'] ?? ''));

        $staff     = $this->db->all('staff');
        $staffMap  = [];
        foreach ($staff as $s) $staffMap[$s['id']] = $s['name'];

        $diagMap = [];
        foreach ($this->db->all('diagnoses') as $d) $diagMap[$d['id']] = $d['diagnosis_name'] ?? $d['name'] ?? '';

        $prodMap = [];
        foreach ($this->db->all('products') as $pr) $prodMap[$pr['id']] = $pr['product_name'] ?? $pr['name'] ?? '';

        $visitRows = [];
        foreach ($visits as $v) {
            $visitRows[] = [
                'id'           => $v['id'],
                'visit_date'   => $v['visit_date'],
                'doctor_name'  => $staffMap[$v['doctor_id'] ?? ''] ?? 'N/A',
                'diagnosis_name' => $diagMap[$v['diagnosis_id'] ?? ''] ?? '',
                'product_name'   => $prodMap[$v['product_id'] ?? ''] ?? '',
            ];
        }
        $patient['visits']       = $visitRows;
        $patient['total_visits'] = count($visitRows);

        $diagnoses = array_filter($visitRows, fn($v) => !empty($v['diagnosis_name']));
        $patient['diagnoses_history'] = array_values(array_map(fn($v) => [
            'diagnosis_date' => $v['visit_date'],
            'diagnosis_name' => $v['diagnosis_name'],
            'doctor_name'    => $v['doctor_name'],
        ], $diagnoses));

        $prescriptions = array_filter($visitRows, fn($v) => !empty($v['product_name']));
        $patient['prescriptions_history'] = array_values(array_map(fn($v) => [
            'prescribed_date' => $v['visit_date'],
            'medicine_name'   => $v['product_name'],
            'doctor_name'     => $v['doctor_name'],
        ], $prescriptions));
        $patient['total_prescriptions'] = count($prescriptions);

        Response::success("Patient fetched", $patient);
    }

    /** GET /api/patients/{id}/diagnoses */
    public function diagnoses($id)
    {
        Auth::authorize(['admin', 'doctor', 'staff', 'user']);
        $all = $this->db->all('visits');
        $rows = array_filter($all, fn($v) => (string)$v['patient_id'] === (string)$id);

        $staffMap = [];
        foreach ($this->db->all('staff') as $s) $staffMap[$s['id']] = $s['name'];
        $prodMap = [];
        foreach ($this->db->all('products') as $p) $prodMap[$p['id']] = $p['product_name'] ?? $p['name'] ?? '';
        $diagMap = [];
        foreach ($this->db->all('diagnoses') as $d) $diagMap[$d['id']] = $d['diagnosis_name'] ?? $d['name'] ?? '';

        $result = array_values(array_map(fn($v) => array_merge($v, [
            'doctor_name'    => $staffMap[$v['doctor_id'] ?? ''] ?? '',
            'product_name'   => $prodMap[$v['product_id'] ?? ''] ?? '',
            'diagnosis_name' => $diagMap[$v['diagnosis_id'] ?? ''] ?? '',
        ]), $rows));

        usort($result, fn($a, $b) => strcmp($b['visit_date'] ?? '', $a['visit_date'] ?? ''));
        Response::success("Diagnosis history fetched", $result);
    }

    /** POST /api/patients */
    public function store()
    {
        $user   = Auth::authorize(['doctor', 'staff']);
        $errors = Request::validateRequired(['name', 'email', 'mobile']);
        if (!empty($errors)) Response::validationError($errors);

        $diagId = Request::input('diagnosis_id');
        $prodId = Request::input('product_id');

        if ($user['role'] === 'staff' && ($diagId !== null || $prodId !== null)) {
            Response::forbidden("Staff cannot assign diagnoses or medicines");
        }

        $email    = Request::input('email');
        $prefix   = explode('@', $email)[0];
        $uniqueId = $this->db->generateUniqueId($prefix);

        $row = $this->db->insert('patients', [
            'unique_id'    => $uniqueId,
            'name'         => Request::input('name'),
            'email'        => $email,
            'mobile'       => Request::input('mobile'),
            'age'          => Request::input('age', ''),
            'address'      => Request::input('address', ''),
            'city'         => Request::input('city', ''),
            'state'        => Request::input('state', ''),
            'pincode'      => Request::input('pincode', ''),
            'symptoms'     => Request::input('symptoms', ''),
            'added_by'     => $user['id'],
            'relative_id'  => Request::input('relative_id', ''),
            'diagnosis_id' => ($diagId !== '' && $diagId !== null) ? $diagId : '',
            'product_id'   => ($prodId !== '' && $prodId !== null) ? $prodId : '',
            'soft_delete'  => '0',
        ]);

        // Auto-log visit
        if (($diagId !== '' && $diagId !== null) || ($prodId !== '' && $prodId !== null)) {
            $this->db->insert('visits', [
                'patient_id'   => $row['id'],
                'doctor_id'    => $user['id'],
                'diagnosis_id' => ($diagId !== '' && $diagId !== null) ? $diagId : '',
                'product_id'   => ($prodId !== '' && $prodId !== null) ? $prodId : '',
                'visit_date'   => date('Y-m-d'),
            ]);
        }

        Response::success("Patient added successfully", ['id' => $row['id']], 201);
    }

    /** PUT /api/patients/{id} */
    public function update($id)
    {
        $user    = Auth::authorize(['doctor', 'staff']);
        $patient = $this->db->findById('patients', $id);
        if (!$patient) Response::notFound("Patient not found");

        $diagId = Request::input('diagnosis_id');
        $prodId = Request::input('product_id');

        if ($user['role'] === 'staff' && ($diagId !== null || $prodId !== null)) {
            Response::forbidden("Staff cannot assign diagnoses or medicines");
        }

        $allowed = ['name', 'email', 'mobile', 'age', 'address', 'city', 'state', 'pincode', 'symptoms', 'relative_id'];
        if (in_array($user['role'], ['doctor'])) {
            $allowed[] = 'diagnosis_id';
            $allowed[] = 'product_id';
        }

        $fields = [];
        foreach ($allowed as $field) {
            $value = Request::input($field);
            if ($value !== null) $fields[$field] = $value;
        }
        if (empty($fields)) Response::error("Nothing to update", 400);

        $this->db->update('patients', $id, $fields);

        if (($diagId !== '' && $diagId !== null) || ($prodId !== '' && $prodId !== null)) {
            $this->db->insert('visits', [
                'patient_id'   => $id,
                'doctor_id'    => $user['id'],
                'diagnosis_id' => ($diagId !== '' && $diagId !== null) ? $diagId : '',
                'product_id'   => ($prodId !== '' && $prodId !== null) ? $prodId : '',
                'visit_date'   => date('Y-m-d'),
            ]);
        }

        Response::success("Patient updated successfully");
    }

    /** DELETE /api/patients/{id} */
    public function destroy($id)
    {
        Auth::authorize(['doctor', 'staff']);
        $ok = $this->db->softDelete('patients', $id);
        if (!$ok) Response::notFound("Patient not found");
        Response::success("Patient deleted successfully");
    }

    /** GET /api/patients/by-mobile?mobile= */
    public function byMobile()
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $mobile = Request::query('mobile');
        if (empty($mobile)) Response::success("No mobile query", []);

        $all    = $this->db->all('patients');
        $result = array_values(array_filter($all, fn($p) => $p['mobile'] === $mobile));
        $result = array_map(fn($p) => ['id' => $p['id'], 'unique_id' => $p['unique_id'], 'name' => $p['name'], 'mobile' => $p['mobile']], $result);

        Response::success("Patients fetched by mobile", $result);
    }
}
