<?php
/**
 * Staff Profile Page
 * View and edit staff profile
 */

ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['staff_id'])) {
    ob_end_clean();
    header('Location: index.php');
    exit;
}

require_once 'config/database.php';

$staff_id = $_SESSION['staff_id'];

// Get staff info
$stmt = $pdo->prepare("
    SELECT s.*, d.department_name, r.role_name
    FROM staff s
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN staff_roles r ON s.staff_role_id = r.role_id
    WHERE s.staff_id = ?
");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'] ?? $staff['phone'];
    $email = $_POST['email'] ?? $staff['email'];
    
    // Handle profile image upload
    $profile_image = $staff['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/staff/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filename = 'staff_' . $staff_id . '_' . time() . '.' . $ext;
        $target = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target)) {
            $profile_image = 'uploads/staff/' . $filename;
        }
    }
    
    try {
        $updateStmt = $pdo->prepare("
            UPDATE staff 
            SET phone = ?, email = ?, profile_image = ?
            WHERE staff_id = ?
        ");
        $updateStmt->execute([$phone, $email, $profile_image, $staff_id]);
        
        // Update session
        $_SESSION['staff_email'] = $email;
        $_SESSION['staff_image'] = $profile_image;
        
        $success = "Profile updated successfully!";
        
        // Refresh staff data
        $stmt->execute([$staff_id]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error = "Error updating profile: " . $e->getMessage();
    }
}

$page_title = 'My Profile';
$page_icon = 'fas fa-user';
$active_page = 'profile';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'dashboard.php'],
    ['title' => 'My Profile']
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<style>
    .profile-card {
        background: var(--white);
        border-radius: 20px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .profile-card-header {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        padding: 30px 35px;
        color: var(--white);
        display: flex;
        align-items: center;
        gap: 25px;
        flex-wrap: wrap;
    }
    .profile-avatar-large {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 4px solid rgba(255,255,255,0.3);
        flex-shrink: 0;
    }
    .profile-avatar-large img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .profile-avatar-large i {
        font-size: 3rem;
        color: var(--primary-color);
    }
    .profile-name-title {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 5px;
    }
    .profile-role-title {
        opacity: 0.85;
        font-size: 0.95rem;
    }
    .profile-card-body {
        padding: 30px 35px;
    }
    .profile-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    .profile-info-item {
        padding: 15px;
        background: var(--gray-100);
        border-radius: 12px;
    }
    .profile-info-item .label {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: var(--text-light);
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    .profile-info-item .value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-top: 2px;
    }
    .profile-info-item .value i {
        color: var(--primary-color);
        margin-right: 8px;
        width: 18px;
    }
    .profile-form .form-control {
        border-radius: 12px;
        border: 1.5px solid var(--gray-200);
        padding: 10px 15px;
        font-size: 0.95rem;
        transition: var(--transition);
    }
    .profile-form .form-control:focus {
        border-color: var(--primary-light);
        box-shadow: 0 0 0 3px rgba(63, 116, 156, 0.1);
    }
    .profile-form .form-label {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-dark);
    }
    .btn-update-profile {
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-weight: 700;
        color: var(--white);
        transition: var(--transition);
    }
    .btn-update-profile:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(63, 116, 156, 0.4);
        color: var(--white);
    }
    .file-upload-wrapper {
        position: relative;
        overflow: hidden;
        display: inline-block;
    }
    .file-upload-wrapper input[type=file] {
        position: absolute;
        left: 0;
        top: 0;
        opacity: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }
    .file-upload-btn {
        padding: 8px 20px;
        border-radius: 10px;
        border: 1.5px dashed var(--gray-300);
        background: var(--gray-100);
        color: var(--text-light);
        font-weight: 600;
        font-size: 0.85rem;
        transition: var(--transition);
        display: inline-block;
    }
    .file-upload-btn:hover {
        border-color: var(--primary-light);
        background: var(--primary-soft);
        color: var(--primary-color);
    }
</style>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-avatar-large">
                    <?php if (!empty($staff['profile_image']) && file_exists('../' . $staff['profile_image'])): ?>
                        <img src="../<?php echo htmlspecialchars($staff['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <i class="fas fa-user-tie"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="profile-name-title">
                        <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                    </div>
                    <div class="profile-role-title">
                        <i class="fas fa-user-tag me-1"></i>
                        <?php echo htmlspecialchars($staff['role'] ?? $staff['role_name'] ?? 'Staff'); ?>
                        <span class="mx-2">|</span>
                        <i class="fas fa-building me-1"></i>
                        <?php echo htmlspecialchars($staff['department_name'] ?? 'N/A'); ?>
                    </div>
                </div>
            </div>
            
            <div class="profile-card-body">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Info Grid -->
                <div class="profile-info-grid mb-4">
                    <div class="profile-info-item">
                        <div class="label">Staff Number</div>
                        <div class="value"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($staff['staff_number']); ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="label">Email Address</div>
                        <div class="value"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($staff['email']); ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="label">Phone Number</div>
                        <div class="value"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($staff['phone'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="label">Employment Type</div>
                        <div class="value"><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($staff['employment_type'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="label">Contract Status</div>
                        <div class="value"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($staff['contract_status'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="profile-info-item">
                        <div class="label">Joined Date</div>
                        <div class="value"><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($staff['created_at'] ?? 'now')); ?></div>
                    </div>
                </div>
                
                <!-- Update Form -->
                <form method="POST" action="" class="profile-form" enctype="multipart/form-data">
                    <h5 class="mb-3"><i class="fas fa-edit me-2 text-primary"></i> Update Profile</h5>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($staff['email']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($staff['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Profile Image</label>
                            <div class="file-upload-wrapper">
                                <span class="file-upload-btn">
                                    <i class="fas fa-upload me-2"></i> Choose Image
                                </span>
                                <input type="file" name="profile_image" accept="image/*">
                            </div>
                            <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                Recommended: Square image, max 2MB (JPG, PNG, GIF)
                            </div>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn-update-profile">
                                <i class="fas fa-save me-2"></i> Update Profile
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ob_end_flush(); ?>