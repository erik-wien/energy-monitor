<?php
/**
 * inc/_admin_user_modals.php — Create and edit modals for the users tab.
 * Required from admin.php when $tab === 'users'.
 */
?>
<!-- Create Modal -->
<div class="modal" id="createModal" aria-labelledby="createModalTitle">
    <div class="modal-dialog">
        <div class="modal-header">
            <h5 class="modal-title" id="createModalTitle">Benutzer anlegen</h5>
            <button type="button" class="btn-close" data-modal-close aria-label="Schließen">&times;</button>
        </div>
        <form id="createForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="createUsername">Benutzername</label>
                    <input type="text" id="createUsername" name="username" class="form-control" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="createEmail">E-Mail</label>
                    <input type="email" id="createEmail" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="createRights">Rechte</label>
                    <select id="createRights" name="rights" class="form-control">
                        <option value="User">User</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-modal-close>Abbrechen</button>
                <button type="submit" class="btn btn-primary">Anlegen &amp; einladen</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal" aria-labelledby="editModalTitle">
    <div class="modal-dialog">
        <div class="modal-header">
            <h5 class="modal-title" id="editModalTitle">Benutzer bearbeiten: <span id="editUsername"></span></h5>
            <button type="button" class="btn-close" data-modal-close aria-label="Schließen">&times;</button>
        </div>
        <form id="editForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" id="editId">
                <div class="form-group">
                    <label for="editEmail">E-Mail</label>
                    <input type="email" id="editEmail" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="editRights">Rechte</label>
                    <select id="editRights" name="rights" class="form-control">
                        <option value="User">User</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editDisabled" name="disabled" value="1">
                    <label for="editDisabled">Deaktiviert</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editDebug" name="debug" value="1">
                    <label for="editDebug">Debug-Modus</label>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="editTotpReset" name="totp_reset" value="1">
                    <label for="editTotpReset">2FA zurücksetzen</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-modal-close>Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>
