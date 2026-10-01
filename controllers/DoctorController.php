<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

class DoctorController
{
    private $db;

    public function __construct(SheetsDB $db) { $this->db = $db; }

    private function getDoctors(string $search = ''): array
    {
        $staff   = $this->db->all('staff');
        $doctors = array_filter($staff, fn($s) => $s['role'] === 'doctor');
        if ($search) {
            $q       = strtolower($search);
            $doctors = array_filter($doctors, fn($d) => str_contains(strtolower($d['name'] ?? ''), $q) || str_contains(strtolower($d['email'] ?? ''), $q));
        }
        $doctorProfiles = $this->db->all('doctors');
        $profMap = [];
        foreach ($doctorProfiles as $dp) $profMap[$dp['staff_id']] = $dp;

        $result = [];
        foreach ($doctors as $d) {
            $prof     = $profMap[$d['id']] ?? [];
            $result[] = [
                'id'                 => $d['id'],
                'name'               => $d['name'],
                'email'              => $d['email'],
                'mobile'             => $d['mobile'] ?? '',
                'description'        => $d['description'] ?? '',
                'created_at'         => $d['created_at'] ?? '',
                'doctor_profile_id'  => $prof['id'] ?? '',
                'specialization'     => $prof['specialization'] ?? '',
                'qualification'      => $prof['qualification'] ?? '',
                'experience_years'   => $prof['experience_years'] ?? '',
            ];
        }
        return $result;
    }

    /** GET /api/doctors */
    public function index()
    {
        Auth::authorize(['admin', 'doctor']);
        $pg      = Request::pagination();
        $search  = Request::query('search', '');
        $doctors = $this->getDoctors($search);
        usort($doctors, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $total   = count($doctors);
        $doctors = array_slice($doctors, $pg['offset'], $pg['per_page']);
        Response::success("Doctors fetched", [
            'doctors'    => array_values($doctors),
            'pagination' => Request::paginated($total, $pg['page'], $pg['per_page']),
        ]);
    }

    /** GET /api/doctors/list */
    public function list()
    {
        Auth::authorize(['admin', 'doctor']);
        $staff   = $this->db->all('staff');
        $doctors = array_values(array_map(
            fn($s) => ['id' => $s['id'], 'name' => $s['name']],
            array_filter($staff, fn($s) => $s['role'] === 'doctor')
        ));
        Response::success("Doctor list fetched", $doctors);
    }

    /** GET /api/doctors/{id} */
    public function show($id)
    {
        Auth::authorize(['admin', 'doctor']);
        $doctors = $this->getDoctors();
        foreach ($doctors as $d) {
            if ((string)$d['id'] === (string)$id) Response::success("Doctor fetched", $d);
        }
        Response::notFound("Doctor not found");
    }

    /** POST /api/doctors */
    public function store()
    {
        Auth::authorize(['admin']);
        $errors = Request::validateRequired(['name', 'email']);
        if (!empty($errors)) Response::validationError($errors);

        $email = Request::input('email');
        if ($this->db->findOne('staff', 'email', $email)) {
            Response::error("A staff account with this email already exists", 409);
        }

        $staffRow = $this->db->insert('staff', [
            'name'        => Request::input('name'),
            'email'       => $email,
            'password'    => Request::input('password', 'Doctor@123'),
            'mobile'      => Request::input('mobile', ''),
            'role'        => 'doctor',
            'description' => Request::input('description', ''),
            'soft_delete' => '0',
        ]);

        $this->db->insert('doctors', [
            'staff_id'         => $staffRow['id'],
            'specialization'   => Request::input('specialization', ''),
            'qualification'    => Request::input('qualification', ''),
            'experience_years' => Request::input('experience_years', '0'),
        ]);

        Response::success("Doctor added successfully", ['id' => $staffRow['id']], 201);
    }

    /** PUT /api/doctors/{id} */
    public function update($id)
    {
        Auth::authorize(['admin']);
        $staffFields = [];
        foreach (['name', 'email', 'mobile', 'description'] as $f) {
            $v = Request::input($f);
            if ($v !== null) $staffFields[$f] = $v;
        }
        if (!empty($staffFields)) $this->db->update('staff', $id, $staffFields);

        $doctorFields = [];
        foreach (['specialization', 'qualification', 'experience_years'] as $f) {
            $v = Request::input($f);
            if ($v !== null) $doctorFields[$f] = $v;
        }
        if (!empty($doctorFields)) {
            $dp = $this->db->findOne('doctors', 'staff_id', $id);
            if ($dp) $this->db->update('doctors', $dp['id'], $doctorFields);
        }

        Response::success("Doctor updated successfully");
    }

    /** DELETE /api/doctors/{id} */
    public function destroy($id)
    {
        Auth::authorize(['admin']);
        $ok = $this->db->softDelete('staff', $id);
        if (!$ok) Response::notFound("Doctor not found");
        Response::success("Doctor deleted successfully");
    }
}
