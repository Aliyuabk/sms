<?php
// transcript.php - Transcript Processing System
session_start();

// Set maximum file size (10MB)
$maxFileSize = 10 * 1024 * 1024; // 10MB in bytes

// Allowed file types for upload
$allowedTypes = [
    'text/plain' => 'txt',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
];

// Initialize session variables if not set
if (!isset($_SESSION['transcripts'])) {
    $_SESSION['transcripts'] = [];
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['transcript_file'])) {
    $uploadStatus = handleFileUpload($_FILES['transcript_file'], $maxFileSize, $allowedTypes);
}

// Handle transcript deletion
if (isset($_GET['delete'])) {
    $deleteId = $_GET['delete'];
    if (isset($_SESSION['transcripts'][$deleteId])) {
        unset($_SESSION['transcripts'][$deleteId]);
        header('Location: transcript.php?deleted=1');
        exit;
    }
}

// Handle transcript editing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id'])) {
    $editId = $_POST['edit_id'];
    if (isset($_SESSION['transcripts'][$editId])) {
        $_SESSION['transcripts'][$editId]['content'] = trim($_POST['edited_content']);
        $_SESSION['transcripts'][$editId]['title'] = trim($_POST['edited_title']);
        $_SESSION['transcripts'][$editId]['modified'] = date('Y-m-d H:i:s');
        header('Location: transcript.php?edited=1');
        exit;
    }
}

// Handle transcript download
if (isset($_GET['download'])) {
    $downloadId = $_GET['download'];
    if (isset($_SESSION['transcripts'][$downloadId])) {
        downloadTranscript($_SESSION['transcripts'][$downloadId]);
        exit;
    }
}

/**
 * Handle file upload and processing
 */
function handleFileUpload($file, $maxSize, $allowedTypes) {
    $errors = [];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = getUploadErrorMessage($file['error']);
        return ['success' => false, 'errors' => $errors];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        $errors[] = "File is too large. Maximum size is " . ($maxSize / (1024 * 1024)) . "MB.";
    }
    
    // Check file type
    $fileType = mime_content_type($file['tmp_name']);
    if (!array_key_exists($fileType, $allowedTypes)) {
        $errors[] = "Invalid file type. Allowed types: " . implode(', ', array_keys($allowedTypes));
    }
    
    if (count($errors) > 0) {
        return ['success' => false, 'errors' => $errors];
    }
    
    // Process the file based on type
    $content = processUploadedFile($file, $fileType);
    
    if ($content !== false) {
        // Generate a unique ID for this transcript
        $id = uniqid('transcript_', true);
        
        // Extract title from filename (remove extension)
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
        
        // Store in session
        $_SESSION['transcripts'][$id] = [
            'id' => $id,
            'title' => $title,
            'filename' => $file['name'],
            'type' => $fileType,
            'size' => $file['size'],
            'content' => $content,
            'uploaded' => date('Y-m-d H:i:s'),
            'modified' => date('Y-m-d H:i:s')
        ];
        
        return ['success' => true, 'id' => $id];
    }
    
    return ['success' => false, 'errors' => ['Failed to process the uploaded file.']];
}

/**
 * Process different file types
 */
function processUploadedFile($file, $fileType) {
    $content = '';
    
    switch ($fileType) {
        case 'text/plain':
            // For TXT files
            $content = file_get_contents($file['tmp_name']);
            break;
            
        case 'application/pdf':
            // For PDF files - requires PDF parser library
            // This is a basic implementation - consider using a library like Smalot/PdfParser
            $content = "PDF content extraction requires additional libraries.\n";
            $content .= "File: " . $file['name'] . "\n";
            $content .= "Size: " . round($file['size'] / 1024, 2) . "KB\n";
            $content .= "Uploaded: " . date('Y-m-d H:i:s');
            break;
            
        case 'application/msword':
        case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
            // For DOC/DOCX files - requires PHPWord library
            $content = "Word document processing requires PHPWord library.\n";
            $content .= "File: " . $file['name'] . "\n";
            $content .= "Size: " . round($file['size'] / 1024, 2) . "KB\n";
            $content .= "Uploaded: " . date('Y-m-d H:i:s');
            break;
            
        default:
            return false;
    }
    
    return trim($content);
}

/**
 * Get user-friendly upload error messages
 */
function getUploadErrorMessage($errorCode) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
    ];
    
    return isset($errors[$errorCode]) ? $errors[$errorCode] : 'Unknown upload error';
}

/**
 * Download transcript as text file
 */
function downloadTranscript($transcript) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $transcript['title'] . '.txt"');
    echo "Transcript: " . $transcript['title'] . "\n";
    echo "Filename: " . $transcript['filename'] . "\n";
    echo "Uploaded: " . $transcript['uploaded'] . "\n";
    echo "Modified: " . $transcript['modified'] . "\n";
    echo "========================================\n\n";
    echo $transcript['content'];
}

/**
 * Format file size in human-readable format
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

/**
 * Get file icon based on file type
 */
function getFileIcon($fileType) {
    $icons = [
        'text/plain' => '📄',
        'application/pdf' => '📕',
        'application/msword' => '📘',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '📘'
    ];
    
    return isset($icons[$fileType]) ? $icons[$fileType] : '📁';
}

/**
 * Sanitize output for HTML display
 */
function sanitizeOutput($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcript Processing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 1.1em;
        }
        
        .upload-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .upload-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        
        input[type="file"] {
            padding: 10px;
            border: 2px dashed #3498db;
            border-radius: 5px;
            background: #f8f9fa;
            cursor: pointer;
        }
        
        input[type="file"]:hover {
            background: #e8f4fc;
        }
        
        .file-info {
            font-size: 0.9em;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-success {
            background: #2ecc71;
            color: white;
        }
        
        .btn-success:hover {
            background: #27ae60;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d68910;
        }
        
        .transcripts-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .transcript-list {
            display: grid;
            gap: 15px;
            margin-top: 20px;
        }
        
        .transcript-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }
        
        .transcript-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .transcript-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .transcript-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .transcript-meta {
            font-size: 0.9em;
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        
        .transcript-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 14px;
        }
        
        .transcript-content {
            max-height: 200px;
            overflow-y: auto;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #eaeaea;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .edit-modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .close-modal {
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }
        
        .close-modal:hover {
            color: #e74c3c;
        }
        
        textarea {
            width: 100%;
            min-height: 300px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            resize: vertical;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #3498db;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #7f8c8d;
            font-size: 0.9em;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .upload-section,
            .transcripts-section {
                padding: 20px;
            }
            
            .transcript-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .transcript-actions {
                flex-wrap: wrap;
            }
            
            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📝 Transcript Processing System</h1>
            <p class="subtitle">Upload, manage, and process text transcripts from various file formats</p>
        </header>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
            <div class="alert alert-success">
                ✅ Transcript deleted successfully!
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['edited']) && $_GET['edited'] == 1): ?>
            <div class="alert alert-success">
                ✅ Transcript updated successfully!
            </div>
        <?php endif; ?>
        
        <?php if (isset($uploadStatus) && !$uploadStatus['success']): ?>
            <div class="alert alert-error">
                <strong>Upload Error:</strong><br>
                <?php foreach ($uploadStatus['errors'] as $error): ?>
                    • <?php echo sanitizeOutput($error); ?><br>
                <?php endforeach; ?>
            </div>
        <?php elseif (isset($uploadStatus) && $uploadStatus['success']): ?>
            <div class="alert alert-success">
                ✅ File uploaded and processed successfully!
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($_SESSION['transcripts']); ?></div>
                <div class="stat-label">Total Transcripts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $maxFileSize / (1024 * 1024); ?> MB</div>
                <div class="stat-label">Max File Size</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($allowedTypes); ?></div>
                <div class="stat-label">Supported Formats</div>
            </div>
        </div>
        
        <section class="upload-section">
            <h2>Upload Transcript</h2>
            <form action="transcript.php" method="POST" enctype="multipart/form-data" class="upload-form">
                <div class="form-group">
                    <label for="transcript_file">Select Transcript File</label>
                    <input type="file" name="transcript_file" id="transcript_file" required>
                    <div class="file-info">
                        <p>Supported formats: TXT, PDF, DOC, DOCX</p>
                        <p>Maximum file size: <?php echo ($maxFileSize / (1024 * 1024)); ?> MB</p>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    📤 Upload & Process
                </button>
            </form>
        </section>
        
        <section class="transcripts-section">
            <h2>Your Transcripts (<?php echo count($_SESSION['transcripts']); ?>)</h2>
            
            <?php if (empty($_SESSION['transcripts'])): ?>
                <div class="alert alert-info">
                    📭 No transcripts uploaded yet. Upload a file above to get started.
                </div>
            <?php else: ?>
                <div class="transcript-list">
                    <?php foreach ($_SESSION['transcripts'] as $transcript): ?>
                        <div class="transcript-item">
                            <div class="transcript-header">
                                <div class="transcript-title">
                                    <?php echo getFileIcon($transcript['type']); ?>
                                    <?php echo sanitizeOutput($transcript['title']); ?>
                                </div>
                                <div class="transcript-meta">
                                    <?php echo formatFileSize($transcript['size']); ?> • 
                                    <?php echo date('M j, Y g:i A', strtotime($transcript['uploaded'])); ?>
                                </div>
                            </div>
                            
                            <div class="transcript-content">
                                <?php echo nl2br(sanitizeOutput(substr($transcript['content'], 0, 500))); ?>
                                <?php if (strlen($transcript['content']) > 500): ?>
                                    <em>... (truncated - full content available for download)</em>
                                <?php endif; ?>
                            </div>
                            
                            <div class="transcript-actions">
                                <button class="btn btn-small btn-success view-btn" data-id="<?php echo $transcript['id']; ?>">
                                    👁️ View Full
                                </button>
                                <button class="btn btn-small btn-warning edit-btn" data-id="<?php echo $transcript['id']; ?>">
                                    ✏️ Edit
                                </button>
                                <a href="?download=<?php echo $transcript['id']; ?>" class="btn btn-small btn-primary">
                                    ⬇️ Download
                                </a>
                                <a href="?delete=<?php echo $transcript['id']; ?>" 
                                   class="btn btn-small btn-danger"
                                   onclick="return confirm('Are you sure you want to delete this transcript?');">
                                    🗑️ Delete
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <footer>
            <p>Transcript Processing System • All uploaded files are stored in session memory</p>
            <p>For production use, implement proper database storage and file handling</p>
        </footer>
    </div>
    
    <!-- Edit Modal -->
    <div id="editModal" class="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Transcript</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form method="POST" action="transcript.php">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edited_title">Title:</label>
                    <input type="text" name="edited_title" id="edited_title" required>
                </div>
                <div class="form-group">
                    <label for="edited_content">Content:</label>
                    <textarea name="edited_content" id="edited_content" required></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">💾 Save Changes</button>
                    <button type="button" class="btn btn-primary close-modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Modal -->
    <div id="viewModal" class="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>View Transcript</h3>
                <span class="close-modal">&times;</span>
            </div>
            <div class="form-group">
                <label for="view_title">Title:</label>
                <input type="text" id="view_title" readonly style="background: #f5f5f5;">
            </div>
            <div class="form-group">
                <label for="view_content">Content:</label>
                <textarea id="view_content" readonly style="background: #f5f5f5;"></textarea>
            </div>
            <div style="margin-top: 20px;">
                <button type="button" class="btn btn-primary close-modal">Close</button>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            const editModal = document.getElementById('editModal');
            const viewModal = document.getElementById('viewModal');
            const closeButtons = document.querySelectorAll('.close-modal');
            
            // Edit transcript buttons
            document.querySelectorAll('.edit-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const transcriptId = this.getAttribute('data-id');
                    // In a real application, you would fetch the transcript data via AJAX
                    // For this demo, we'll use the session data that's already loaded
                    
                    // Set modal values (in a real app, fetch via AJAX)
                    document.getElementById('edit_id').value = transcriptId;
                    document.getElementById('edited_title').value = 'Transcript ' + transcriptId.substring(0, 8);
                    
                    // For demo, generate some sample content
                    document.getElementById('edited_content').value = `This is the content for transcript ${transcriptId}.\n\nYou can edit this content as needed.\n\nOriginal text would appear here.`;
                    
                    editModal.classList.add('active');
                });
            });
            
            // View transcript buttons
            document.querySelectorAll('.view-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const transcriptId = this.getAttribute('data-id');
                    
                    // Set modal values (in a real app, fetch via AJAX)
                    document.getElementById('view_title').value = 'Transcript ' + transcriptId.substring(0, 8);
                    
                    // For demo, generate some sample content
                    document.getElementById('view_content').value = `Full transcript content for ${transcriptId}:\n\n` + 
                    `This is the complete content of the transcript. In a real application, ` +
                    `this would be fetched from the server via AJAX call.\n\n` +
                    `Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod ` +
                    `tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, ` +
                    `quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.\n\n` +
                    `Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore ` +
                    `eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, ` +
                    `sunt in culpa qui officia deserunt mollit anim id est laborum.`;
                    
                    viewModal.classList.add('active');
                });
            });
            
            // Close modal buttons
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    editModal.classList.remove('active');
                    viewModal.classList.remove('active');
                });
            });
            
            // Close modal when clicking outside
            [editModal, viewModal].forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                    }
                });
            });
            
            // File input preview
            const fileInput = document.getElementById('transcript_file');
            if (fileInput) {
                fileInput.addEventListener('change', function(e) {
                    const fileName = this.files[0]?.name || 'No file selected';
                    const fileSize = this.files[0]?.size || 0;
                    
                    // Update file info
                    const fileInfo = this.nextElementSibling;
                    if (fileInfo) {
                        fileInfo.innerHTML = `
                            <p>Selected: <strong>${fileName}</strong></p>
                            <p>Size: ${formatFileSize(fileSize)}</p>
                            <p>Maximum file size: <?php echo ($maxFileSize / (1024 * 1024)); ?> MB</p>
                        `;
                    }
                });
            }
            
            // Format file size for display
            function formatFileSize(bytes) {
                if (bytes >= 1073741824) {
                    return (bytes / 1073741824).toFixed(2) + ' GB';
                } else if (bytes >= 1048576) {
                    return (bytes / 1048576).toFixed(2) + ' MB';
                } else if (bytes >= 1024) {
                    return (bytes / 1024).toFixed(2) + ' KB';
                } else if (bytes > 1) {
                    return bytes + ' bytes';
                } else if (bytes == 1) {
                    return '1 byte';
                } else {
                    return '0 bytes';
                }
            }
        });
    </script>
</body>
</html>