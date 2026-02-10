<?php

namespace Fbs\trpay\Auth;

class Auth
{
    protected $pdo;

    protected $cookieDuration = 30 * 24 * 60 * 60; // 30 days

    // Define the maximum allowed failed attempts and lockout duration
    private $maxFailedAttempts = 5;

    private $lockoutTime = 900; // in seconds (15 minutes)

    private $maxIPAttempts = 30;

    private $ipLockoutTime = 3600; // in seconds (1 hour)

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function check()
    {
        return isset($_SESSION['user_id']);
    }

    public function login($user)
    {
        $this->setSessionVariables($user);

        header('Location: /');
        exit;
    }

    private function setSessionVariables($user)
    {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_name'] = $user['user_name'];
        $_SESSION['user_role'] = $user['user_role'];
        $_SESSION['logged'] = true;
        $_SESSION['user_avatar'] = $user['user_avatar'];
    }

    public function createUser($user_name, $user_email, $password, $user_role = 'admin', $user_avatar = null)
    {
        // Check if email already exists
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE user_email = :email');
        $stmt->execute(['email' => $user_email]);
        if ($stmt->fetchColumn() > 0) {
            throw new \Exception('User email already exists.');
        }

        // Securely hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert user details into the database
        $stmt = $this->pdo->prepare('INSERT INTO users (user_name, user_email, user_password, user_role, user_avatar, user_created_at) VALUES (:name, :email, :password, :role, :avatar, NOW())');
        $stmt->execute([
            'name' => $user_name,
            'email' => $user_email,
            'password' => $hashed_password,
            'role' => $user_role,
            'avatar' => $user_avatar,
        ]);

        $user_id = $this->pdo->lastInsertId();

        // Retrieve the user from the database to verify the data
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user_id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Return the newly created user's ID
        return $user_id;
    }

    private function decryptUserSpecificKey($user_encryption_ciphertext, $user_password)
    {
        // Get the IV, salt and ciphertext
        $salt = substr($user_encryption_ciphertext, 0, 16);
        $iv = substr($user_encryption_ciphertext, 16, 16);
        $ciphertext = substr($user_encryption_ciphertext, 32);

        // Generate 128-bit (16 byte/char) kdhash of the users password
        $user_password_kdhash = hash_pbkdf2('sha256', $user_password, $salt, 100000, 16);

        // Use this hash to get the original/master key
        return openssl_decrypt($ciphertext, 'aes-128-cbc', $user_password_kdhash, 0, $iv);
    }

    public static function logout($pdo)
    {
        // Clear the session
        unset($_SESSION['user_id']);
        unset($_SESSION['user_encryption_session_ciphertext']);
        unset($_SESSION['user_encryption_session_iv']);
        session_destroy();

        // Clear the remember me cookie
        setcookie('remember_me', '', time() - 3600, '/', '', true, true);
        setcookie('user_encryption_session_key', '', time() - 3600, '/', '', true, true);

        session_unset();

        header('Location: /');
        exit;
    }

    public function getUserAvatar($user_id)
    {
        $stmt = $this->pdo->prepare('SELECT user_avatar FROM users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user_id]);

        return $stmt->fetchColumn();
    }

    public function findUser($email, $password)
    {
        if ($this->isAccountLocked($email)) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            SELECT users.user_id, users.user_name, users.user_password, users.user_role, users.user_avatar, users.user_token
            FROM users
            WHERE user_email = :email
        ');

        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // User found - verify password
        } else {
            return false;
        }

        $password_verified = password_verify($password, $user['user_password']);

        if ($password_verified) {
            // Successful login; clear any failed attempts
            $this->clearFailedLogins($email);

            return [
                'user_id' => $user['user_id'],
                'user_name' => $user['user_name'],
                'user_role' => $user['user_role'],
                'user_token' => $user['user_token'] ?? null,
                'user_avatar' => $user['user_avatar'] ?? null,
                'user_specific_encryption_ciphertext' => $user['user_specific_encryption_ciphertext'] ?? null,
            ];
        } else {
            // Failed login; record the attempt
            $this->recordFailedLogin($email);

            return false;
        }
    }

    public function getUserRole($user_id = null)
    {
        if ($user_id === null) {
            $user_id = $_SESSION['user_id'];
        }
        $stmt = $this->pdo->prepare('SELECT user_role FROM users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user_id]);

        return $stmt->fetchColumn();
    }

    public function isRole($role)
    {
        switch ($role) {
            case 'admin':
                return $this->getUserRole() == 'admin';
            default:
                return false;
        }
    }

    public function updatePassword($user_id, $new_password)
    {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('UPDATE users SET user_password = :password WHERE user_id = :user_id');

        return $stmt->execute(['password' => $hashed_password, 'user_id' => $user_id]);
    }

    public function updateUserField($user_id, $field, $value)
    {
        // Whitelist of allowed fields to prevent SQL injection
        $allowedFields = [
            'user_name',
            'user_email',
            'user_avatar',
            'user_role',
            'user_token',
            'user_archived_at',
        ];

        if (! in_array($field, $allowedFields, true)) {
            throw new \InvalidArgumentException("Invalid field name: {$field}");
        }

        $stmt = $this->pdo->prepare("UPDATE users SET {$field} = :value WHERE user_id = :user_id");

        return $stmt->execute(['value' => $value, 'user_id' => $user_id]);
    }

    public function getUser($user_id = null)
    {
        if ($user_id === null) {
            $user_id = $_SESSION['user_id'];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE users.user_id = :user_id');
        $stmt->execute(['user_id' => $user_id]);

        return $stmt->fetch();
    }

    public function getUsername($user_id = null)
    {
        if ($user_id === null) {
            $user_id = $_SESSION['user_id'];
        }

        return $this->getUser($user_id)['user_name'];
    }

    public function getUsers()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users ORDER BY user_archived_at ASC, user_role ASC');
        $stmt->execute();

        return $stmt->fetchAll($this->pdo::FETCH_ASSOC);
    }

    private function generateUserSessionKey($encryptionMasterKey)
    {
        // Generate a random session key
        $sessionKey = bin2hex(random_bytes(32));

        // Store it in the session for encryption-related operations
        $_SESSION['user_encryption_session_key'] = openssl_encrypt(
            $sessionKey,
            'aes-128-cbc',
            $encryptionMasterKey,
            0,
            substr($encryptionMasterKey, 0, 16) // Using the first 16 bytes as IV
        );

        return $sessionKey;
    }

    public function getMailQueue($sent = false)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_queue WHERE email_status = :status ORDER BY email_queued_at DESC');
        $stmt->execute(['status' => $sent ? 3 : 0]);

        return $stmt->fetchAll($this->pdo::FETCH_ASSOC);
    }

    // Record a failed login attempt
    private function recordFailedLogin($email)
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        $this->recordIPFailedLogin($ip);

        $stmt = $this->pdo->prepare('
            INSERT INTO login_attempts (email, attempt_time)
            VALUES (:email, NOW())
        ');
        $stmt->execute(['email' => $email]);
    }

    // Check if the account is locked
    public function isAccountLocked($email)
    {
        // Calculate the time window for counting failed attempts
        $timeWindow = date('Y-m-d H:i:s', time() - $this->lockoutTime);

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM login_attempts
            WHERE email = :email AND attempt_time > :timeWindow
        ');
        $stmt->execute(['email' => $email, 'timeWindow' => $timeWindow]);
        $attempts = $stmt->fetchColumn();

        return $attempts >= $this->maxFailedAttempts;
    }

    // Clear failed login attempts after successful login
    private function clearFailedLogins($email)
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
        $stmt->execute(['email' => $email]);
    }

    private function recordIPFailedLogin($ip)
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO ip_login_attempts (ip_address, attempt_time)
            VALUES (:ip_address, NOW())
        ');
        $stmt->execute(['ip_address' => $ip]);
    }

    public function isIPBlocked($ip)
    {
        $timeWindow = date('Y-m-d H:i:s', time() - $this->ipLockoutTime);

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM ip_login_attempts
            WHERE ip_address = :ip_address AND attempt_time > :timeWindow
        ');
        $stmt->execute(['ip_address' => $ip, 'timeWindow' => $timeWindow]);
        $attempts = $stmt->fetchColumn();

        return $attempts >= $this->maxIPAttempts;
    }
}
