<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}
require_once 'dbcon.php';
global $con;

// Handle Add Category
if (isset($_POST['add_category'])) {
    $name = mysqli_real_escape_string($con, $_POST['cat_name']);
    $icon = mysqli_real_escape_string($con, $_POST['cat_icon']);
    $sql = "INSERT INTO skill_categories (name, icon) VALUES (?, ?)";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $name, $icon);
    mysqli_stmt_execute($stmt);
    header('Location: manage_skills.php');
    exit;
}

// Handle Add Skill
if (isset($_POST['add_skill'])) {
    $name = mysqli_real_escape_string($con, $_POST['skill_name']);
    $desc = mysqli_real_escape_string($con, $_POST['skill_desc']);
    $cat_id = (int)$_POST['category_id'];
    $sql = "INSERT INTO skills (category_id, name, description) VALUES (?, ?, ?)";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "iss", $cat_id, $name, $desc);
    mysqli_stmt_execute($stmt);
    header('Location: manage_skills.php');
    exit;
}

// Handle Add Assessment
if (isset($_POST['add_assessment'])) {
    $title = mysqli_real_escape_string($con, $_POST['ass_title']);
    $desc = mysqli_real_escape_string($con, $_POST['ass_desc']);
    $skill_id = (int)$_POST['skill_id'];
    $time_limit = (int)$_POST['time_limit'];
    $passing_score = (int)$_POST['passing_score'];
    $is_premium = isset($_POST['is_premium']) ? 1 : 0;
    
    $sql = "INSERT INTO skill_assessments (skill_id, title, description, time_limit, passing_score, is_premium) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($con, $sql);
    mysqli_stmt_bind_param($stmt, "issiii", $skill_id, $title, $desc, $time_limit, $passing_score, $is_premium);
    mysqli_stmt_execute($stmt);
    header('Location: manage_skills.php');
    exit;
}

// Fetch categories
$cats_result = mysqli_query($con, "SELECT * FROM skill_categories ORDER BY name");
$categories = [];
while ($row = mysqli_fetch_assoc($cats_result)) {
    $categories[] = $row;
}

// Fetch skills with assessments
$skills_result = mysqli_query($con, "
    SELECT s.*, c.name as cat_name, COUNT(a.id) as assessment_count 
    FROM skills s 
    JOIN skill_categories c ON s.category_id = c.id 
    LEFT JOIN skill_assessments a ON s.id = a.skill_id
    GROUP BY s.id 
    ORDER BY c.name, s.name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Verified Skills | Admin Dashboard</title>
    <?php include '../includes/links.php'; ?>
    <style>
        .admin-wrap { max-width: 1200px; margin: 0 auto; padding: 30px; }
        .card { border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; }
    </style>
</head>
<body class="bg-light">
    <?php include 'header.php'; ?>
    
    <div class="admin-wrap">
        <h2 class="mb-4"><i class="fas fa-medal text-primary"></i> Manage Verified Skills</h2>
        
        <div class="row">
            <!-- Add Forms -->
            <div class="col-md-4">
                <div class="card p-4">
                    <h4>Add Category</h4>
                    <form method="post">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="cat_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Icon (FontAwesome Class)</label>
                            <input type="text" name="cat_icon" class="form-control" placeholder="fas fa-code">
                        </div>
                        <button type="submit" name="add_category" class="btn btn-primary w-100">Add Category</button>
                    </form>
                </div>
                
                <div class="card p-4">
                    <h4>Add Skill</h4>
                    <form method="post">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" class="form-control" required>
                                <?php foreach($categories as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="skill_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="skill_desc" class="form-control" rows="2"></textarea>
                        </div>
                        <button type="submit" name="add_skill" class="btn btn-primary w-100">Add Skill</button>
                    </form>
                </div>
            </div>
            
            <!-- List Skills & Add Assessments -->
            <div class="col-md-8">
                <div class="card p-4">
                    <h4>Skills Catalog</h4>
                    <table class="table table-bordered table-striped mt-3">
                        <thead class="thead-light">
                            <tr>
                                <th>Category</th>
                                <th>Skill Name</th>
                                <th>Assessments</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($s = mysqli_fetch_assoc($skills_result)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['cat_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td><span class="badge badge-info"><?php echo $s['assessment_count']; ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#addAssModal" 
                                                data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>">
                                            <i class="fas fa-plus"></i> Assessment
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Assessment Modal -->
    <div class="modal fade" id="addAssModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Assessment to <span id="assSkillName"></span></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="skill_id" id="assSkillId">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="ass_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="ass_desc" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Time Limit (mins)</label>
                            <input type="number" name="time_limit" class="form-control" value="20" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Passing Score (%)</label>
                            <input type="number" name="passing_score" class="form-control" value="60" required>
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_premium" class="form-check-input" id="isPremCheck">
                        <label class="form-check-label" for="isPremCheck">Premium (Requires PRO)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" name="add_assessment" class="btn btn-primary">Save Assessment</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#addAssModal').on('show.bs.modal', function (event) {
            var button = $(event.relatedTarget);
            var id = button.data('id');
            var name = button.data('name');
            var modal = $(this);
            modal.find('#assSkillId').val(id);
            modal.find('#assSkillName').text(name);
        });
    </script>
</body>
</html>
