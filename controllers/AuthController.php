<?php
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Request.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/JWT.php';

class AuthController
{
    private $db;

    public function __construct(SheetsDB $db)
    {
        $this->db = $db;
    }

    /** POST /api/auth/login */
    public function login()
    {
        $errors = Request::validateRequired(['email', 'password']);
        if (!empty($errors)) Response::validationError($errors);

        $email    = Request::input('email');
        $password = Request::input('password');

        // Search staff sheet
        $account = $this->db->findOne('staff', 'email', $email);
        if (!$account || $password !== $account['password'] || !empty($account['soft_delete']) && $account['soft_delete'] === '1') {
            Response::error("Invalid email or password", 401);
        }

        $token = JWT::encode([
            'id'    => $account['id'],
            'name'  => $account['name'],
            'email' => $account['email'],
            'role'  => $account['role'],
        ]);

        unset($account['password'], $account['_row']);
        Response::success("Login successful", ['token' => $token, 'user' => $account]);
    }

    /** POST /api/auth/logout */
    public function logout()
    {
        Auth::authenticate();
        Response::success("Logged out successfully");
    }

    /** POST /api/auth/register */
    public function register()
    {
        $errors = Request::validateRequired(['name', 'email', 'password', 'role']);
        if (!empty($errors)) Response::validationError($errors);

        $role = Request::input('role');
        if (!in_array($role, ['doctor', 'staff'], true)) Response::error("Role must be doctor or staff", 422);

        $email = Request::input('email');
        if ($this->db->findOne('staff', 'email', $email)) {
            Response::error("A user with this email already exists", 409);
        }

        $row = $this->db->insert('staff', [
            'name'        => Request::input('name'),
            'email'       => $email,
            'password'    => Request::input('password'),
            'mobile'      => Request::input('mobile', ''),
            'role'        => $role,
            'description' => '',
            'soft_delete' => '0',
        ]);

        Response::success("Registration successful", ['id' => $row['id']], 201);
    }

    /** GET /api/auth/profile */
    public function profile()
    {
        $user    = Auth::authenticate();
        $account = $this->db->findById('staff', $user['id']);
        if (!$account) Response::notFound("Account not found");

        unset($account['password'], $account['_row']);
        $account['role'] = $user['role'];
        Response::success("Profile fetched", $account);
    }

    /** PUT /api/auth/profile */
    public function updateProfile()
    {
        $user   = Auth::authenticate();
        $fields = [];
        $name   = Request::input('name');
        $mobile = Request::input('mobile');
        if ($name !== null)   $fields['name']   = $name;
        if ($mobile !== null) $fields['mobile'] = $mobile;
        if (empty($fields)) Response::error("Nothing to update", 400);

        $this->db->update('staff', $user['id'], $fields);
        Response::success("Profile updated successfully");
    }

    /** POST /api/auth/change-password */
    public function changePassword()
    {
        $user   = Auth::authenticate();
        $errors = Request::validateRequired(['old_password', 'new_password']);
        if (!empty($errors)) Response::validationError($errors);

        $account = $this->db->findById('staff', $user['id']);
        if (!$account || Request::input('old_password') !== $account['password']) {
            Response::error("Old password is incorrect", 400);
        }

        $newPass = Request::input('new_password');
        if (strlen($newPass) < 6) Response::error("New password must be at least 6 characters", 422);

        $this->db->update('staff', $user['id'], ['password' => $newPass]);
        Response::success("Password changed successfully");
    }

    /** POST /api/auth/forgot-password */
    public function forgotPassword()
    {
        $errors = Request::validateRequired(['email']);
        if (!empty($errors)) Response::validationError($errors);

        $email   = Request::input('email');
        $account = $this->db->findOne('staff', 'email', $email);

        if (!$account) {
            Response::success("If that email exists, a reset link has been generated");
        }

        $resetToken = bin2hex(random_bytes(24));
        $expiresAt  = date('Y-m-d H:i:s', time() + 3600);

        $this->db->insert('password_resets', [
            'email'        => $email,
            'account_type' => 'staff',
            'reset_token'  => $resetToken,
            'expires_at'   => $expiresAt,
            'used'         => '0',
        ]);

        // In production: email karein. Development mein token return karte hain.
        Response::success("Reset token generated (demo mode)", ['reset_token' => $resetToken]);
    }

    /** POST /api/auth/reset-password */
    public function resetPassword()
    {
        $errors = Request::validateRequired(['reset_token', 'new_password']);
        if (!empty($errors)) Response::validationError($errors);

        $resetToken = Request::input('reset_token');
        $newPass    = Request::input('new_password');

        $resets  = $this->db->readSheet('password_resets');
        $resetRow = null;
        foreach ($resets as $r) {
            if ($r['reset_token'] === $resetToken && $r['used'] === '0') {
                $resetRow = $r;
                break;
            }
        }

        if (!$resetRow || strtotime($resetRow['expires_at']) < time()) {
            Response::error("Reset token is invalid or expired", 400);
        }
        if (strlen($newPass) < 6) Response::error("New password must be at least 6 characters", 422);

        $account = $this->db->findOne('staff', 'email', $resetRow['email']);
        if ($account) {
            $this->db->update('staff', $account['id'], ['password' => $newPass]);
        }
        $this->db->update('password_resets', $resetRow['id'], ['used' => '1']);

        Response::success("Password reset successfully");
    }
}
