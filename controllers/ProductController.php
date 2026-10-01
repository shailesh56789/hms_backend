<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';

class ProductController
{
    private $db;

    public function __construct(SheetsDB $db) { $this->db = $db; }

    /** GET /api/products */
    public function index()
    {
        Auth::authorize(['admin', 'staff', 'doctor']);
        $pg     = Request::pagination();
        $search = Request::query('search');
        $all    = $search
            ? $this->db->search('products', ['product_name', 'description'], $search)
            : $this->db->all('products');

        $total = count($all);
        $items = array_slice($all, $pg['offset'], $pg['per_page']);

        Response::success("Products fetched", [
            'products'   => array_values($items),
            'pagination' => Request::paginated($total, $pg['page'], $pg['per_page']),
        ]);
    }

    /** GET /api/products/list */
    public function list()
    {
        Auth::authorize(['admin', 'staff', 'doctor']);
        $all  = $this->db->all('products');
        $list = array_values(array_map(
            fn($p) => ['id' => $p['id'], 'name' => $p['product_name'] ?? $p['name'] ?? ''],
            $all
        ));
        Response::success("Product list fetched", $list);
    }

    /** GET /api/products/{id} */
    public function show($id)
    {
        Auth::authorize(['admin', 'staff', 'doctor']);
        $p = $this->db->findById('products', $id);
        if (!$p || (!empty($p['soft_delete']) && $p['soft_delete'] === '1')) Response::notFound("Product not found");
        // Expose product_id for frontend compatibility
        $p['product_id'] = $p['id'];
        Response::success("Product fetched", $p);
    }

    /** POST /api/products */
    public function store()
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $errors = Request::validateRequired(['product_name']);
        if (!empty($errors)) Response::validationError($errors);

        $row = $this->db->insert('products', [
            'product_name'   => Request::input('product_name'),
            'description'    => Request::input('description', ''),
            'price'          => Request::input('price', ''),
            'stock_quantity' => Request::input('stock_quantity', '0'),
            'soft_delete'    => '0',
        ]);

        Response::success("Product added successfully", ['id' => $row['id']], 201);
    }

    /** PUT /api/products/{id} */
    public function update($id)
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $p = $this->db->findById('products', $id);
        if (!$p || (!empty($p['soft_delete']) && $p['soft_delete'] === '1')) Response::notFound("Product not found");

        $fields = [];
        foreach (['product_name', 'description', 'price', 'stock_quantity'] as $f) {
            $v = Request::input($f);
            if ($v !== null) $fields[$f] = $v;
        }
        if (empty($fields)) Response::error("Nothing to update", 400);

        $this->db->update('products', $id, $fields);
        Response::success("Product updated successfully");
    }

    /** DELETE /api/products/{id} */
    public function destroy($id)
    {
        Auth::authorize(['admin', 'doctor', 'staff']);
        $ok = $this->db->softDelete('products', $id);
        if (!$ok) Response::notFound("Product not found");
        Response::success("Product deleted successfully");
    }
}
