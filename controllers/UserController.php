<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

class UserController
{
    private $db;

    public function __construct(SheetsDB $db) { $this->db = $db; }

    /** GET /api/users */
    public function index()
    {
        Auth::authorize(['doctor', 'staff']);
        $pg     = Request::pagination();
        $search = Request::query('search');
        $all    = $search
            ? $this->db->search('staff', ['name', 'email'], $search)
            : $this->db->all('staff');

        $total = count($all);
        $items = array_slice($all, $pg['offset'], $pg['per_page']);
        $items = array_map(function($u) {
            unset($u['password'], $u['_row']);
            return $u;
        }, $items);

        Response::success("Users fetched", [
            'users'      => array_values($items),
            'pagination' => Request::paginated($total, $pg['page'], $pg['per_page']),
        ]);
    }

    /** GET /api/users/{id} */
    public function show($id)
    {
        Auth::authorize(['doctor', 'staff']);
        $u = $this->db->findById('staff', $id);
        if (!$u || (!empty($u['soft_delete']) && $u['soft_delete'] === '1')) Response::notFound("User not found");
        unset($u['password'], $u['_row']);
        Response::success("User fetched", $u);
    }

    /** POST /api/users */
    public function store()
    {
        Auth::authorize(['doctor']);
        $errors = Request::validateRequired(['name', 'email', 'role']);
        if (!empty($errors)) Response::validationError($errors);

        $role = Request::input('role');
        if (!in_array($role, ['doctor', 'staff'], true)) Response::error("Role must be doctor or staff", 422);

        $email = Request::input('email');
        if ($this->db->findOne('staff', 'email', $email)) Response::error("A user with this email already exists", 409);

        $row = $this->db->insert('staff', [
            'name'        => Request::input('name'),
            'email'       => $email,
            'password'    => Request::input('password', 'Staff@123'),
            'mobile'      => Request::input('mobile', ''),
            'role'        => $role,
            'description' => Request::input('description', ''),
            'soft_delete' => '0',
        ]);

        Response::success("User added successfully", ['id' => $row['id']], 201);
    }

    /** PUT /api/users/{id} */
    public function update($id)
    {
        Auth::authorize(['doctor']);
        $u = $this->db->findById('staff', $id);
        if (!$u || (!empty($u['soft_delete']) && $u['soft_delete'] === '1')) Response::notFound("User not found");

        $role = Request::input('role');
        if ($role !== null && !in_array($role, ['doctor', 'staff'], true)) Response::error("Role must be doctor or staff", 422);

        $fields = [];
        foreach (['name', 'email', 'mobile', 'role', 'description'] as $f) {
            $v = Request::input($f);
            if ($v !== null) $fields[$f] = $v;
        }
        if (empty($fields)) Response::error("Nothing to update", 400);

        $this->db->update('staff', $id, $fields);
        Response::success("User updated successfully");
    }

    /** DELETE /api/users/{id} */
    public function destroy($id)
    {
        $current = Auth::authorize(['doctor']);
        if ((int)$current['id'] === (int)$id) Response::error("You cannot delete your own account", 400);
        $ok = $this->db->softDelete('staff', $id);
        if (!$ok) Response::notFound("User not found");
        Response::success("User deleted successfully");
    }
}
