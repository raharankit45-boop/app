<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

if (!isset($_SESSION['user_id']) && isset($_COOKIE['gym_remember'])) {
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE remember_token = ?");
    $stmt->execute([$_COOKIE['gym_remember']]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
    }
}

$action = $_REQUEST['action'] ?? '';
$response = ['success' => false, 'error' => 'Invalid action'];

switch ($action) {
    case 'register':
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $pwd = $_POST['password'] ?? '';
        $pwdConfirm = $_POST['password_confirm'] ?? '';
        $sq = $_POST['security_question'] ?? '';
        $sa = strtolower(trim($_POST['security_answer'] ?? ''));

        if (!$name || !$email || !$pwd || !$pwdConfirm || !$sq || !$sa) {
            $response['error'] = 'All fields are required';
            break;
        }
        if ($pwd !== $pwdConfirm) {
            $response['error'] = 'Passwords do not match';
            break;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $response['error'] = 'Email already registered';
            break;
        }

        $hashedPwd = password_hash($pwd, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, security_question, security_answer) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $hashedPwd, $sq, $sa])) {
            $response = ['success' => true];
        }
        break;

    case 'login':
        $email = $_POST['email'] ?? '';
        $pwd = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $stmt = $pdo->prepare("SELECT id, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pwd, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
                setcookie('gym_remember', $token, time() + (86400 * 30), "/");
            }
            $response = ['success' => true, 'role' => $user['role']];
        } else {
            $response['error'] = 'Invalid email or password';
        }
        break;

    case 'logout':
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        }
        session_destroy();
        setcookie('gym_remember', '', time() - 3600, "/");
        $response = ['success' => true];
        break;

    case 'verify_session':
        if (isset($_SESSION['user_id'])) {
            $response = ['success' => true, 'role' => $_SESSION['role']];
        } else {
            $response['error'] = 'Not authenticated';
        }
        break;

    case 'forgot_password':
        $email = $_POST['email'] ?? '';
        $sq = $_POST['security_question'] ?? '';
        $answer = strtolower(trim($_POST['security_answer'] ?? ''));
        $newPwd = $_POST['new_password'] ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        if ($newPwd !== $confirmPwd) {
            $response['error'] = 'Passwords do not match';
            break;
        }

        $stmt = $pdo->prepare("SELECT id, security_question, security_answer FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['security_question'] === $sq && $user['security_answer'] === $answer) {
            $hashed = password_hash($newPwd, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $user['id']]);
            $response = ['success' => true];
        } else {
            $response['error'] = 'Invalid email, security question, or answer';
        }
        break;

    case 'get_profile':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $stmt = $pdo->prepare("SELECT name, email, security_question FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $response = ['success' => true, 'data' => $stmt->fetch()];
        break;

    case 'update_security':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $sq = $_POST['security_question'] ?? '';
        $sa = strtolower(trim($_POST['security_answer'] ?? ''));
        if (!$sq || !$sa) {
            $response['error'] = 'All fields required';
            break;
        }
        $stmt = $pdo->prepare("UPDATE users SET security_question = ?, security_answer = ? WHERE id = ?");
        $stmt->execute([$sq, $sa, $_SESSION['user_id']]);
        $response = ['success' => true];
        break;

    case 'update_password':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (password_verify($old, $user['password'])) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $_SESSION['user_id']]);
            $response = ['success' => true];
        } else {
            $response['error'] = 'Incorrect old password';
        }
        break;

    case 'get_workouts':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        
        $diff = $_GET['difficulty'] ?? 'All';
        $user_id = $_SESSION['user_id'];
        $date = date('Y-m-d');
        
        $query = "SELECT w.*, c.name as category_name, 
                  IF(p.id IS NOT NULL, 1, 0) as is_completed_today 
                  FROM workouts w 
                  JOIN categories c ON w.category_id = c.id 
                  LEFT JOIN user_progress p ON w.id = p.workout_id AND p.user_id = ? AND p.completed_date = ?";
        
        $params = [$user_id, $date];

        if ($diff !== 'All') {
            $query .= " WHERE w.difficulty = ?";
            $params[] = $diff;
        }
        $query .= " ORDER BY w.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $response = ['success' => true, 'data' => $stmt->fetchAll()];
        break;

    case 'track_progress':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $workout_id = $_POST['workout_id'] ?? 0;
        $date = date('Y-m-d');
        
        $stmt = $pdo->prepare("SELECT id FROM user_progress WHERE user_id = ? AND workout_id = ? AND completed_date = ?");
        $stmt->execute([$_SESSION['user_id'], $workout_id, $date]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO user_progress (user_id, workout_id, completed_date) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $workout_id, $date]);
        }
        $response = ['success' => true];
        break;

    case 'get_progress':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $stmt = $pdo->prepare("
            SELECT w.title, w.difficulty, c.name as category_name, p.completed_date 
            FROM user_progress p 
            JOIN workouts w ON p.workout_id = w.id 
            JOIN categories c ON w.category_id = c.id
            WHERE p.user_id = ? 
            ORDER BY p.completed_date DESC
            LIMIT 10
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $response = ['success' => true, 'data' => $stmt->fetchAll()];
        break;

    case 'get_user_stats':
        if (!isset($_SESSION['user_id'])) {
            $response['error'] = 'Unauthorized';
            break;
        }
        $uid = $_SESSION['user_id'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM user_progress WHERE user_id = ?");
        $stmt->execute([$uid]);
        $total = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT c.name, COUNT(p.id) as count 
            FROM user_progress p 
            JOIN workouts w ON p.workout_id = w.id 
            JOIN categories c ON w.category_id = c.id 
            WHERE p.user_id = ? 
            GROUP BY c.id
        ");
        $stmt->execute([$uid]);
        $categories = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT DISTINCT completed_date 
            FROM user_progress 
            WHERE user_id = ? AND completed_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        ");
        $stmt->execute([$uid]);
        $active_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $response = [
            'success' => true, 
            'data' => [
                'total' => $total,
                'categories' => $categories,
                'active_dates' => $active_dates
            ]
        ];
        break;

    case 'admin_get_stats':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $stats = [];
        $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
        $stats['workouts'] = $pdo->query("SELECT COUNT(*) FROM workouts")->fetchColumn();
        $stats['completions'] = $pdo->query("SELECT COUNT(*) FROM user_progress")->fetchColumn();
        $response = ['success' => true, 'data' => $stats];
        break;

    case 'admin_get_users':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $stmt = $pdo->query("SELECT id, name, email, created_at FROM users WHERE role='user'");
        $response = ['success' => true, 'data' => $stmt->fetchAll()];
        break;

    case 'admin_delete_user':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $id = $_POST['user_id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $response = ['success' => true];
        break;

    case 'admin_user_history':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $id = $_GET['user_id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT w.title, w.difficulty, p.completed_date 
            FROM user_progress p 
            JOIN workouts w ON p.workout_id = w.id 
            WHERE p.user_id = ? 
            ORDER BY p.completed_date DESC
        ");
        $stmt->execute([$id]);
        $response = ['success' => true, 'data' => $stmt->fetchAll()];
        break;

    case 'admin_get_workouts':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $stmt = $pdo->query("SELECT w.*, c.name as category_name FROM workouts w JOIN categories c ON w.category_id = c.id ORDER BY w.created_at DESC");
        $response = ['success' => true, 'data' => $stmt->fetchAll()];
        break;

    case 'admin_add_workout':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $title = $_POST['title'] ?? '';
        $cat = $_POST['category_id'] ?? 1;
        $desc = $_POST['description'] ?? '';
        $diff = $_POST['difficulty'] ?? 'Beginner';
        $inst = $_POST['instructions'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO workouts (category_id, title, description, difficulty, instructions) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$cat, $title, $desc, $diff, $inst]);
        $response = ['success' => true];
        break;

    case 'admin_update_workout':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $id = $_POST['workout_id'] ?? 0;
        $title = $_POST['title'] ?? '';
        $cat = $_POST['category_id'] ?? 1;
        $desc = $_POST['description'] ?? '';
        $diff = $_POST['difficulty'] ?? 'Beginner';
        $inst = $_POST['instructions'] ?? '';
        
        $stmt = $pdo->prepare("UPDATE workouts SET category_id=?, title=?, description=?, difficulty=?, instructions=? WHERE id=?");
        $stmt->execute([$cat, $title, $desc, $diff, $inst, $id]);
        $response = ['success' => true];
        break;

    case 'admin_delete_workout':
        if (($_SESSION['role'] ?? '') !== 'admin') break;
        $id = $_POST['workout_id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM workouts WHERE id = ?");
        $stmt->execute([$id]);
        $response = ['success' => true];
        break;
}

echo json_encode($response);
?>