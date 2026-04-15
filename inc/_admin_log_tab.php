<?php
/**
 * inc/_admin_log_tab.php — Log tab body.
 * Required from admin.php when $tab === 'log'. Expects:
 *   $logRows, $logTotal, $logPage, $logLastPage, $logPerPage,
 *   $logApps, $logContexts, $logFilters
 */
?>
<div class="pref-card">
    <div class="pref-card-hdr">Log (<?= (int) $logTotal ?> Einträge)</div>
    <div class="pref-card-body">

        <form method="get" action="admin.php" class="form-inline" style="display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; align-items:end">
            <input type="hidden" name="tab" value="log">

            <div class="form-group">
                <label for="log_app">App</label>
                <select id="log_app" name="log_app" class="form-control">
                    <option value="">Alle</option>
                    <?php foreach ($logApps as $a): ?>
                        <option value="<?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>" <?= $logFilters['app'] === $a ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="log_context">Kontext</label>
                <select id="log_context" name="log_context" class="form-control">
                    <option value="">Alle</option>
                    <?php foreach ($logContexts as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= $logFilters['context'] === $c ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="log_user">Benutzer</label>
                <input type="text" id="log_user" name="log_user" class="form-control"
                       value="<?= htmlspecialchars($logFilters['user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="username">
            </div>

            <div class="form-group">
                <label for="log_from">Von</label>
                <input type="text" id="log_from" name="log_from" class="form-control"
                       value="<?= htmlspecialchars($logFilters['from'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">
            </div>

            <div class="form-group">
                <label for="log_to">Bis</label>
                <input type="text" id="log_to" name="log_to" class="form-control"
                       value="<?= htmlspecialchars($logFilters['to'], ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">
            </div>

            <div class="form-group" style="flex:1; min-width:14rem">
                <label for="log_q">Suche in Aktivität</label>
                <input type="text" id="log_q" name="log_q" class="form-control"
                       value="<?= htmlspecialchars($logFilters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Text">
            </div>

            <div class="form-check" style="align-self:center">
                <input type="checkbox" id="log_fail" name="log_fail" value="1" <?= $logFilters['fail'] ? 'checked' : '' ?>>
                <label for="log_fail">nur Fehler</label>
            </div>

            <div class="form-group" style="display:flex; gap:.5rem">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a class="btn" href="admin.php?tab=log">Zurücksetzen</a>
            </div>
        </form>

        <table class="table log-table">
            <thead>
                <tr>
                    <th>Zeit</th>
                    <th>App</th>
                    <th>Kontext</th>
                    <th>Benutzer</th>
                    <th>IP</th>
                    <th>Aktivität</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logRows as $r): ?>
                <tr>
                    <td class="log-time"><?= htmlspecialchars($r['logTime'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['origin'],  ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['context'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $r['username'] !== null ? htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                    <td><?= $r['ip'] !== null ? htmlspecialchars($r['ip'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>' ?></td>
                    <td class="log-activity"><?= htmlspecialchars($r['activity'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($logRows)): ?>
                <tr><td colspan="6" class="text-muted">Keine Einträge gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ($logLastPage > 1): ?>
            <nav class="pagination">
                <?php for ($p = 1; $p <= $logLastPage; $p++): ?>
                    <a class="page-link<?= $p === $logPage ? ' active' : '' ?>"
                       href="<?= htmlspecialchars(log_page_url($p, $logFilters), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>

    </div>
</div>
