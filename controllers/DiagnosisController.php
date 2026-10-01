<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

class DiagnosisController
{
    private $db;

    public function __construct(SheetsDB $db) { $this->db = $db; }

    /** GET /api/diagnoses */
    public function index()
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $pg      = Request::pagination();
        $search  = Request::query('search');
        $all     = $search
            ? $this->db->search('diagnoses', ['diagnosis_name', 'description'], $search)
            : $this->db->all('diagnoses');

        usort($all, fn($a, $b) => (int)($a['id'] ?? 0) - (int)($b['id'] ?? 0));
        $total = count($all);
        $items = array_slice($all, $pg['offset'], $pg['per_page']);

        Response::success("Diagnoses fetched", [
            'diagnoses'  => array_values($items),
            'pagination' => Request::paginated($total, $pg['page'], $pg['per_page']),
        ]);
    }

    /** GET /api/diagnoses/{id} */
    public function show($id)
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $d = $this->db->findById('diagnoses', $id);
        if (!$d || (!empty($d['soft_delete']) && $d['soft_delete'] === '1')) Response::notFound("Diagnosis not found");
        Response::success("Diagnosis fetched", $d);
    }

    /** POST /api/diagnoses */
    public function store()
    {
        Auth::authorize(['admin', 'doctor']);
        $errors = Request::validateRequired(['diagnosis_name']);
        if (!empty($errors)) Response::validationError($errors);

        $row = $this->db->insert('diagnoses', [
            'diagnosis_name' => Request::input('diagnosis_name'),
            'description'    => Request::input('description', ''),
            'status'         => Request::input('status', 'active'),
            'soft_delete'    => '0',
        ]);

        Response::success("Diagnosis added successfully", ['id' => $row['id']], 201);
    }

    /** PUT /api/diagnoses/{id} */
    public function update($id)
    {
        Auth::authorize(['admin', 'doctor']);
        $d = $this->db->findById('diagnoses', $id);
        if (!$d || (!empty($d['soft_delete']) && $d['soft_delete'] === '1')) Response::notFound("Diagnosis not found");

        $fields = [];
        foreach (['diagnosis_name', 'description', 'status'] as $f) {
            $v = Request::input($f);
            if ($v !== null) $fields[$f] = $v;
        }
        if (empty($fields)) Response::error("Nothing to update", 400);

        $this->db->update('diagnoses', $id, $fields);
        Response::success("Diagnosis updated successfully");
    }

    /** DELETE /api/diagnoses/{id} */
    public function destroy($id)
    {
        Auth::authorize(['admin', 'doctor']);
        $ok = $this->db->softDelete('diagnoses', $id);
        if (!$ok) Response::notFound("Diagnosis not found");
        Response::success("Diagnosis deleted successfully");
    }
}
