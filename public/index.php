<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = current_user();
$initialState = [
    'csrf' => csrf_token(),
    'user' => $user ? user_public($user) : null,
    'limits' => [
        'items' => MAX_LIST_ITEMS,
        'itemLength' => MAX_ITEM_LENGTH,
        'notes' => MAX_NOTES_LENGTH,
    ],
];
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> · needs &amp; skills around the world</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><circle cx='8' cy='8' r='7' fill='%2320c997'/></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>

<nav class="navbar navbar-expand-sm fixed-top app-navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2" href="./">
            <i class="bi bi-globe-americas text-info"></i>
            <span class="fw-semibold"><?= e(APP_NAME) ?></span>
        </a>

        <div class="d-flex align-items-center gap-2 ms-auto">
            <?php if ($user): ?>
                <button class="btn btn-info btn-sm" type="button" data-action="edit-profile">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span id="nav-pin-label" class="d-none d-sm-inline"><?= $user['lat'] !== null ? 'Edit my pin' : 'Add my pin' ?></span>
                </button>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <span id="nav-user-name" class="d-none d-sm-inline"><?= e($user['name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><button class="dropdown-item" type="button" data-action="fly-home"><i class="bi bi-crosshair me-2"></i>Show my pin</button></li>
                        <li><button class="dropdown-item" type="button" data-action="logout"><i class="bi bi-box-arrow-right me-2"></i>Log out</button></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button class="dropdown-item text-danger" type="button" data-action="delete-account"><i class="bi bi-trash3 me-2"></i>Delete account</button></li>
                    </ul>
                </div>
            <?php else: ?>
                <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#loginModal">Log in</button>
                <button class="btn btn-info btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#registerModal">
                    <i class="bi bi-plus-lg"></i> Join the map
                </button>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main id="globe-wrap">
    <div id="globe" aria-label="Interactive 3D globe with people pins"></div>

    <div id="globe-loading" class="position-absolute top-50 start-50 translate-middle text-secondary">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading globe…
    </div>

    <div id="pick-banner" class="pick-banner shadow" hidden>
        <i class="bi bi-hand-index-thumb me-2"></i>Click on the globe to set your location
        <button type="button" class="btn btn-sm btn-outline-light ms-3" data-action="cancel-pick">Cancel</button>
    </div>

    <div class="map-stats small text-secondary">
        <i class="bi bi-people-fill me-1"></i><span id="pin-count">0</span> people on the map
    </div>

    <div class="map-hint small text-secondary d-none d-md-block">
        Drag to rotate · scroll to zoom · click a pin for details
    </div>
</main>

<!-- Floating info card shown when a pin is clicked -->
<div id="pin-card" class="card pin-card shadow-lg" hidden role="dialog" aria-live="polite"></div>

<!-- Login -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="login-form" novalidate>
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="loginTitle">Log in</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 small" data-form-error hidden></div>
                <div class="mb-3">
                    <label class="form-label" for="login-email">Email</label>
                    <input class="form-control" id="login-email" name="email" type="email" autocomplete="email" required>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="login-password">Password</label>
                    <input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-link px-0" data-bs-toggle="modal" data-bs-target="#registerModal">No account yet?</button>
                <button type="submit" class="btn btn-info">Log in</button>
            </div>
        </form>
    </div>
</div>

<!-- Register -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="register-form" novalidate>
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="registerTitle">Join the map</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 small" data-form-error hidden></div>
                <div class="mb-3">
                    <label class="form-label" for="reg-name">Name</label>
                    <input class="form-control" id="reg-name" name="name" maxlength="<?= MAX_NAME_LENGTH ?>" autocomplete="name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reg-email">Email</label>
                    <input class="form-control" id="reg-email" name="email" type="email" autocomplete="email" required>
                    <div class="form-text">Only used to log in. It is never shown on the map.</div>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="reg-password">Password</label>
                    <input class="form-control" id="reg-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                    <div class="form-text">At least 8 characters.</div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-link px-0" data-bs-toggle="modal" data-bs-target="#loginModal">Already registered?</button>
                <button type="submit" class="btn btn-info">Create account</button>
            </div>
        </form>
    </div>
</div>

<?php if ($user): ?>
<!-- Profile / pin editor -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form class="modal-content" id="profile-form" novalidate>
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="profileTitle"><i class="bi bi-geo-alt-fill text-info me-2"></i>My pin</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 small" data-form-error hidden></div>

                <div class="row g-3">
                    <div class="col-md-4 text-center">
                        <div class="avatar-preview mx-auto mb-2" id="avatar-preview"></div>
                        <label class="btn btn-outline-secondary btn-sm" for="pf-picture">
                            <i class="bi bi-image me-1"></i>Choose picture
                        </label>
                        <input class="d-none" id="pf-picture" name="picture" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                        <button type="button" class="btn btn-link btn-sm text-danger d-block mx-auto" id="pf-remove-picture" hidden>Remove picture</button>
                        <div class="form-text">Optional</div>
                    </div>

                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label" for="pf-name">Name</label>
                            <input class="form-control" id="pf-name" name="name" maxlength="<?= MAX_NAME_LENGTH ?>" required>
                        </div>

                        <div class="mb-1 position-relative">
                            <label class="form-label" for="pf-location-search">Location</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input class="form-control" id="pf-location-search" type="search" placeholder="Search a city…" autocomplete="off">
                                <button class="btn btn-outline-info" type="button" data-action="start-pick">
                                    <i class="bi bi-globe2 me-1"></i>Pick on globe
                                </button>
                            </div>
                            <div class="list-group location-results shadow" id="location-results" hidden></div>
                        </div>
                        <div class="small mb-3" id="pf-location-current"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf-needs-input"><i class="bi bi-life-preserver text-warning me-1"></i>What I need</label>
                        <div class="tag-input form-control" data-tag-input="needs" data-tag-class="text-bg-warning">
                            <input id="pf-needs-input" type="text" placeholder="e.g. Web design – press Enter">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="pf-skills-input"><i class="bi bi-tools text-success me-1"></i>Skills I offer</label>
                        <div class="tag-input form-control" data-tag-input="skills" data-tag-class="text-bg-success">
                            <input id="pf-skills-input" type="text" placeholder="e.g. Carpentry – press Enter">
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label d-flex justify-content-between" for="pf-notes">
                            <span>Notes</span><span class="text-secondary small" id="pf-notes-count"></span>
                        </label>
                        <textarea class="form-control" id="pf-notes" name="notes" rows="4" maxlength="<?= MAX_NOTES_LENGTH ?>" placeholder="Anything else people should know…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info"><i class="bi bi-pin-map-fill me-1"></i>Save pin</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>

<script>window.APP_STATE = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/globe.gl@2.46.2/dist/globe.gl.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
