<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تۆمارکردن - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset('images/logo.png'); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset('images/logo.png'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/auth-login.css'); ?>" rel="stylesheet">
</head>
<body class="login-page register-page">
    <div class="login-shell">
        <aside class="login-showcase">
            <div class="login-showcase-grid" aria-hidden="true"></div>
            <div class="login-showcase-inner">
                <div class="showcase-brand">
                    <div class="showcase-logo"><img src="<?php echo asset('images/logo.png'); ?>" alt="<?php echo SITE_NAME; ?>"></div>
                    <div class="showcase-brand-text">
                        <strong><?php echo SITE_NAME; ?></strong>
                        <span>سیستەمی کاشیر و بەڕێوەبردن</span>
                    </div>
                </div>
                <h1>ئەکاونتی نوێ دروست بکە و دەستپێبکە</h1>
                <p>تۆمارکردن چەند خولەکێک دەخایەنێت. دواتر دەتوانیت فرۆشتن، کۆگا و کڕیارەکانت لە یەک شوێن بەڕێوەببەیت.</p>
                <ul class="login-showcase-list">
                    <li><i class="bi bi-lightning-charge-fill"></i><span>POS خێرا بە پشتگیری ئۆفلاین</span></li>
                    <li><i class="bi bi-box-seam"></i><span>کۆگا و ئاگادارکردنەوەی کاتی ڕاست</span></li>
                    <li><i class="bi bi-graph-up-arrow"></i><span>ڕاپۆرت و شیکاری بە بڕیاردان</span></li>
                </ul>
                <div class="showcase-stat-row">
                    <div class="showcase-stat"><strong>+۱۲K</strong><span>بازرگانی</span></div>
                    <div class="showcase-stat"><strong>٪۹۹.۹</strong><span>کاتی کارکردن</span></div>
                    <div class="showcase-stat"><strong>۲۴/۷</strong><span>پشتگیری</span></div>
                </div>
            </div>
        </aside>

        <main class="login-main">
            <div class="login-panel">
                <div class="login-card">
                    <div class="login-card-head">
                        <div class="mini-logo"><img src="<?php echo asset('images/logo.png'); ?>" alt="<?php echo SITE_NAME; ?>"></div>
                        <h2><?php echo $success ? 'تۆماربوون تەواو بوو' : 'تۆمارکردن'; ?></h2>
                        <p><?php echo $success ? 'داواکارییەکەت نێردرا — پەیوەندیمان پێوە بکە' : 'زانیارییەکانی شوێنی کارت بنووسە'; ?></p>
                    </div>

                    <div class="login-card-body">
                        <?php if ($success): ?>
                            <div class="register-success">
                                <div class="register-success-icon"><i class="bi bi-check-lg"></i></div>
                                <h4>بەسەرکەوتوویی تۆمار بوویت</h4>
                                <p>داواکارییەکەت بەسەرکەوتوویی نێردراوە. بۆ وەرگرتنی ئەکاونت پەیوەندیمان پێوە بکە.</p>
                            </div>
                            <div class="login-pending-box">
                                <h5><i class="bi bi-lightning-charge-fill"></i> بۆ وەرگرتنی ئەکاونت پەیوەندی بکە</h5>
                                <p class="login-pending-note">پێویستە لە ڕێگەی ئەم شێوازانەوە پەیوەندیمان پێوە بکەیت:</p>
                                <a href="https://t.me/itz_levi0" target="_blank" class="login-submit login-telegram-btn">
                                    <i class="bi bi-telegram"></i> پەیام لە تێلێگرام
                                </a>
                                <div class="login-phone-links">
                                    <a href="tel:07705406115" dir="ltr">0770 540 6115</a>
                                    <a href="tel:07501837582" dir="ltr">0750 183 7582</a>
                                </div>
                            </div>
                            <a href="<?php echo url('user/auth/login.php'); ?>" class="login-submit">
                                <i class="bi bi-box-arrow-in-right"></i> بڕۆ بۆ پەڕەی داخڵبوون
                            </a>
                        <?php else: ?>
                            <?php if (!empty($errors)): ?>
                                <div class="login-alert login-alert-danger" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <div>
                                        <strong>هەڵەکان:</strong>
                                        <ul>
                                            <?php foreach ($errors as $error): ?>
                                                <li><?php echo $error; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if (isset($_GET['error']) && $_GET['error'] === 'size_limit'): ?>
                                            <div class="login-alert-help">
                                                قەبارەی وێنەکان کەم بکەرەوە (کەمتر لە 8MB)، ژمارەیان 3–8 بێت، و فۆرماتی JPG یان PNG بەکاربهێنە.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="register-hint">
                                <i class="bi bi-images"></i>
                                <div>
                                    وێنەی شوێنی کار پێویستە: لانی کەم <?php echo MIN_BUSINESS_IMAGES; ?> وێنە، کەمتر لە <?php echo MAX_BUSINESS_IMAGES; ?>، هەر وێنەیەک تا 8MB.
                                </div>
                            </div>

                            <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate id="registerForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                                <div class="login-field">
                                    <label for="business_name">ناوی شوێنی کار</label>
                                    <div class="login-input-wrap">
                                        <i class="bi bi-shop field-icon"></i>
                                        <input type="text" id="business_name" name="business_name"
                                               value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>"
                                               placeholder="ناوی بازرگانی"
                                               required>
                                    </div>
                                    <div class="invalid-feedback">تکایە ناوی شوێنی کار داخڵ بکە</div>
                                </div>

                                <div class="login-field">
                                    <label for="email">ئیمەیڵ</label>
                                    <div class="login-input-wrap">
                                        <i class="bi bi-envelope field-icon"></i>
                                        <input type="email" id="email" name="email"
                                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                               placeholder="user@email.com"
                                               autocomplete="email"
                                               required>
                                    </div>
                                    <div class="invalid-feedback">تکایە ئیمەیڵێکی دروست داخڵ بکە</div>
                                </div>

                                <div class="login-field">
                                    <label for="phone">ژمارەی مۆبایل</label>
                                    <div class="login-input-wrap">
                                        <i class="bi bi-telephone field-icon"></i>
                                        <input type="tel" id="phone" name="phone"
                                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                               placeholder="07XX XXX XXXX"
                                               required>
                                    </div>
                                    <div class="invalid-feedback">تکایە ژمارەی مۆبایل داخڵ بکە</div>
                                </div>

                                <div class="login-field">
                                    <label for="address">ناونیشانی شوێنی کار</label>
                                    <div class="login-input-wrap align-start">
                                        <i class="bi bi-geo-alt field-icon"></i>
                                        <textarea id="address" name="address" rows="3" placeholder="شار، گەڕەک، ناونیشان" required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="invalid-feedback">تکایە ناونیشان داخڵ بکە</div>
                                </div>

                                <div class="login-field-row">
                                    <div class="login-field">
                                        <label for="password">پاسۆرد</label>
                                        <div class="login-input-wrap">
                                            <i class="bi bi-lock field-icon"></i>
                                            <input type="password" id="password" name="password"
                                                   placeholder="لانی کەم ۸ پیت"
                                                   autocomplete="new-password"
                                                   required>
                                            <button type="button" class="login-toggle-pass" id="togglePassword" aria-label="پیشاندانی پاسورد">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <p class="login-field-hint">لانی کەم ۸ پیت</p>
                                        <div class="invalid-feedback">تکایە پاسۆردێکی بەهێز داخڵ بکە</div>
                                    </div>
                                    <div class="login-field">
                                        <label for="confirm_password">دووپاتکردنەوە</label>
                                        <div class="login-input-wrap">
                                            <i class="bi bi-lock-fill field-icon"></i>
                                            <input type="password" id="confirm_password" name="confirm_password"
                                                   placeholder="پاسۆرد دووبارە"
                                                   autocomplete="new-password"
                                                   required>
                                        </div>
                                        <div class="invalid-feedback">پاسۆردەکان یەک ناگرنەوە</div>
                                    </div>
                                </div>

                                <div class="login-field">
                                    <label>وێنەکانی شوێنی کار</label>
                                    <div class="register-upload" id="fileUploadArea">
                                        <input type="file" name="business_images[]" id="business_images"
                                               multiple accept="image/*" class="d-none" required>
                                        <div class="upload-content" id="uploadContent">
                                            <div class="register-upload-icon"><i class="bi bi-cloud-arrow-up"></i></div>
                                            <h5>وێنەکانی شوێنی کارت هەڵبژێرە</h5>
                                            <p>کلیک بکە یان وێنەکان ڕابکێشە بۆ ئێرە</p>
                                            <button type="button" class="register-browse btn-browse">
                                                <i class="bi bi-folder2-open"></i> هەڵبژاردنی وێنەکان
                                            </button>
                                        </div>
                                    </div>
                                    <div class="register-upload-status" id="uploadStatus" style="display: none;">
                                        <div class="register-upload-status-row">
                                            <span class="file-count-badge" id="fileCountBadge">
                                                <i class="bi bi-images"></i>
                                                <span id="fileCount">0</span> وێنە هەڵبژێردراوە
                                            </span>
                                            <button type="button" class="btn-clear-files" id="clearFiles">
                                                <i class="bi bi-trash"></i> سڕینەوە
                                            </button>
                                        </div>
                                        <div class="mt-2" id="sizeInfo" style="display: none;">
                                            <small class="login-field-hint">کۆی قەبارە: <span id="totalSize">0MB</span> / 20MB</small>
                                            <div class="progress">
                                                <div class="progress-bar" id="sizeProgress" style="width: 0%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="preview-gallery" id="filePreview"></div>
                                </div>

                                <button type="submit" class="login-submit">
                                    <i class="bi bi-person-plus"></i>
                                    داوای دروستکردنی ئەکاونت
                                </button>

                                <div class="login-links">
                                    <a href="<?php echo url('terms_and_conditions.html'); ?>" target="_blank">
                                        <i class="bi bi-file-text"></i> مەرجەکان
                                    </a>
                                    <a href="<?php echo url('user/auth/login.php'); ?>">
                                        <i class="bi bi-box-arrow-in-right"></i> داخڵبوون
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="login-card-foot">
                        ئەکاونتت هەیە؟
                        <a href="<?php echo url('user/auth/login.php'); ?>">داخڵبوون</a>
                    </div>
                </div>

                <div class="login-bottom-links">
                    <a href="<?php echo url(); ?>"><i class="bi bi-house"></i> گەڕانەوە بۆ سەرەکی</a>
                    <span class="mx-2">·</span>
                    <span><i class="bi bi-shield-check"></i> پەیوەندی پارێزراو</span>
                </div>
            </div>
        </main>
    </div>

    <script>
        (function () {
            'use strict';

            var form = document.getElementById('registerForm');
            if (!form) return;

            form.addEventListener('submit', function (event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            });

            var toggle = document.getElementById('togglePassword');
            var password = document.getElementById('password');
            if (toggle && password) {
                toggle.addEventListener('click', function () {
                    var isText = password.type === 'text';
                    password.type = isText ? 'password' : 'text';
                    toggle.innerHTML = isText ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
                });
            }

            var confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword && password) {
                confirmPassword.addEventListener('input', function () {
                    this.setCustomValidity(password.value !== this.value ? 'پاسۆردەکان یەک ناگرنەوە' : '');
                });
            }

            var fileUploadArea = document.getElementById('fileUploadArea');
            var fileInput = document.getElementById('business_images');
            var filePreview = document.getElementById('filePreview');
            var fileCountElement = document.getElementById('fileCount');
            var uploadStatus = document.getElementById('uploadStatus');
            var uploadContent = document.getElementById('uploadContent');
            var clearFilesBtn = document.getElementById('clearFiles');
            var btnBrowse = document.querySelector('.btn-browse');

            if (!fileUploadArea || !fileInput) return;

            var minImages = <?php echo (int) MIN_BUSINESS_IMAGES; ?>;
            var maxImages = <?php echo (int) MAX_BUSINESS_IMAGES; ?>;
            var maxFileSize = 8 * 1024 * 1024;
            var selectedFiles = [];

            if (btnBrowse) {
                btnBrowse.addEventListener('click', function (e) {
                    e.stopPropagation();
                    fileInput.click();
                });
            }

            fileUploadArea.addEventListener('click', function (e) {
                if (e.target === this || e.target === uploadContent) {
                    fileInput.click();
                }
            });

            fileInput.addEventListener('change', function () {
                handleFiles(Array.from(this.files));
            });

            fileUploadArea.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.add('dragover');
            });

            fileUploadArea.addEventListener('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
            });

            fileUploadArea.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('dragover');
                var files = Array.from(e.dataTransfer.files).filter(function (file) {
                    return file.type.startsWith('image/');
                });
                if (files.length > 0) handleFiles(files);
            });

            if (clearFilesBtn) {
                clearFilesBtn.addEventListener('click', clearAllFiles);
            }

            function handleFiles(files) {
                var imageFiles = files.filter(function (file) { return file.type.startsWith('image/'); });
                if (imageFiles.length === 0) {
                    showNotification('تکایە تەنها فایلی وێنە هەڵبژێرە', 'warning');
                    return;
                }
                if (imageFiles.length > maxImages) {
                    showNotification('زیاتر لە ' + maxImages + ' وێنە ڕێگەپێدراو نییە', 'danger');
                    return;
                }
                compressImages(imageFiles);
            }

            function compressImages(files) {
                var compressedFiles = [];
                var processedCount = 0;
                files.forEach(function (file) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        var img = new Image();
                        img.onload = function () {
                            var canvas = document.createElement('canvas');
                            var ctx = canvas.getContext('2d');
                            var dims = calculateDimensions(img.width, img.height, 1920, 1080);
                            canvas.width = dims.width;
                            canvas.height = dims.height;
                            ctx.drawImage(img, 0, 0, dims.width, dims.height);
                            canvas.toBlob(function (blob) {
                                compressedFiles.push(new File([blob], file.name, {
                                    type: 'image/jpeg',
                                    lastModified: Date.now()
                                }));
                                processedCount++;
                                if (processedCount === files.length) {
                                    validateCompressedFiles(compressedFiles);
                                }
                            }, 'image/jpeg', 0.8);
                        };
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                });
            }

            function calculateDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
                var width = originalWidth;
                var height = originalHeight;
                var aspectRatio = originalWidth / originalHeight;
                if (width > maxWidth) {
                    width = maxWidth;
                    height = width / aspectRatio;
                }
                if (height > maxHeight) {
                    height = maxHeight;
                    width = height * aspectRatio;
                }
                return { width: Math.round(width), height: Math.round(height) };
            }

            function validateCompressedFiles(compressedFiles) {
                selectedFiles = compressedFiles;
                updateFileInput();
                displayPreviews();
            }

            function updateFileInput() {
                var dataTransfer = new DataTransfer();
                selectedFiles.forEach(function (file) { dataTransfer.items.add(file); });
                fileInput.files = dataTransfer.files;
            }

            function displayPreviews() {
                if (selectedFiles.length === 0) {
                    uploadContent.style.display = 'block';
                    uploadStatus.style.display = 'none';
                    filePreview.innerHTML = '';
                    return;
                }
                uploadContent.style.display = 'none';
                uploadStatus.style.display = 'block';
                fileCountElement.textContent = selectedFiles.length;
                var badge = document.getElementById('fileCountBadge');
                if (badge) {
                    badge.style.background = selectedFiles.length < minImages
                        ? 'linear-gradient(135deg, #dc3545 0%, #c82333 100%)'
                        : 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)';
                }
                updateSizeInfo();
                filePreview.innerHTML = '';
                selectedFiles.forEach(function (file, index) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        filePreview.appendChild(createPreviewItem(file, e.target.result, index));
                    };
                    reader.readAsDataURL(file);
                });
            }

            function updateSizeInfo() {
                var totalSize = selectedFiles.reduce(function (sum, file) { return sum + file.size; }, 0);
                var totalSizeMB = (totalSize / 1024 / 1024).toFixed(1);
                var percentage = Math.min((totalSize / (20 * 1024 * 1024)) * 100, 100);
                document.getElementById('totalSize').textContent = totalSizeMB + 'MB';
                document.getElementById('sizeProgress').style.width = percentage + '%';
                var sizeInfo = document.getElementById('sizeInfo');
                if (selectedFiles.length > 0) {
                    sizeInfo.style.display = 'block';
                    var progressBar = document.getElementById('sizeProgress');
                    progressBar.className = 'progress-bar ' + (percentage > 90 ? 'bg-danger' : percentage > 70 ? 'bg-warning' : 'bg-success');
                } else {
                    sizeInfo.style.display = 'none';
                }
            }

            function createPreviewItem(file, dataUrl, index) {
                var div = document.createElement('div');
                div.className = 'preview-item';
                var fileName = file.name.length > 20 ? file.name.substring(0, 17) + '...' : file.name;
                div.innerHTML =
                    '<div class="preview-badge">' + (index + 1) + '</div>' +
                    '<div class="btn-remove-image" onclick="removeImage(' + index + ')"><i class="bi bi-x-lg"></i></div>' +
                    '<div class="preview-image-wrapper"><img src="' + dataUrl + '" alt="" class="preview-image"></div>' +
                    '<div class="preview-info"><p class="preview-filename">' + fileName + '</p><p class="preview-size">' + formatFileSize(file.size) + '</p></div>';
                return div;
            }

            window.removeImage = function (index) {
                selectedFiles.splice(index, 1);
                updateFileInput();
                displayPreviews();
            };

            function clearAllFiles() {
                if (selectedFiles.length === 0) return;
                if (confirm('دڵنیایت لە سڕینەوەی هەموو وێنەکان؟')) {
                    selectedFiles = [];
                    updateFileInput();
                    displayPreviews();
                    showNotification('هەموو وێنەکان سڕدرانەوە', 'success');
                }
            }

            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            }

            function showNotification(message, type) {
                var notification = document.createElement('div');
                notification.className = 'register-toast register-toast-' + type;
                notification.textContent = message;
                document.body.appendChild(notification);
                setTimeout(function () { notification.remove(); }, 3000);
            }

            form.addEventListener('submit', function (e) {
                if (selectedFiles.length < minImages) {
                    e.preventDefault();
                    showNotification('لانی کەم ' + minImages + ' وێنە پێویستە', 'danger');
                    fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                if (selectedFiles.length > maxImages) {
                    e.preventDefault();
                    showNotification('زیاتر لە ' + maxImages + ' وێنە ڕێگەپێدراو نییە', 'danger');
                    fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                var totalSize = selectedFiles.reduce(function (sum, file) { return sum + file.size; }, 0);
                if (totalSize > 20 * 1024 * 1024) {
                    e.preventDefault();
                    showNotification('کۆی قەبارەی وێنەکان زۆر گەورەیە. حەدئکثر 20MB', 'danger');
                    fileUploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        })();
    </script>
</body>
</html>
