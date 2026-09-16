<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Administración de Timbres</title>
    <style>
        :root{color-scheme:light;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#243247;background:#f3f5f8}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1180px;margin:38px auto;padding:0 20px}header{display:flex;justify-content:space-between;align-items:end;margin-bottom:20px}h1{font-size:25px;margin:0}small,.muted{color:#708096}.card{background:#fff;border:1px solid #dfe5ec;border-radius:10px;box-shadow:0 2px 8px #26364d0d}.notice{padding:12px 16px;margin-bottom:16px;border-radius:7px}.ok{background:#eaf8ef;color:#23653b}.error{background:#fff0f0;color:#9b2f2f}table{width:100%;border-collapse:collapse}th,td{padding:12px 14px;text-align:left;border-bottom:1px solid #edf0f4;vertical-align:top}th{background:#f8fafc;font-size:12px;text-transform:uppercase;color:#617087}td.num{text-align:right;font-variant-numeric:tabular-nums}.badge{display:inline-block;border-radius:99px;padding:3px 9px;background:#e8eef7;font-size:12px}.available{background:#e7f7ed;color:#267044}.reserved{background:#fff5dd;color:#86651b}.actions{display:flex;gap:8px;align-items:start;flex-wrap:wrap}a,button{color:#245da8}button,.button{border:1px solid #c8d2df;background:#fff;border-radius:6px;padding:7px 10px;text-decoration:none;cursor:pointer;font-size:13px}details{min-width:330px}details[open]{flex-basis:100%}details[open] .formbox{display:grid}.formbox{display:none;position:static;width:100%;min-width:0;margin-top:10px;background:#fff;padding:14px;border:1px solid #ccd5e0;border-radius:8px;box-shadow:0 4px 14px #26364d18;gap:9px}label{font-size:12px;color:#5f6d80}input,select,textarea{display:block;width:100%;padding:8px;border:1px solid #ccd5e0;border-radius:5px;margin-top:3px}textarea{min-height:65px;resize:vertical}.submit{background:#285f9f;color:white;border-color:#285f9f}@media(max-width:850px){.wrap{padding:0 10px}.card{overflow-x:auto}table{min-width:960px}}
    </style>
</head>
<body><main class="wrap">
<header><div><h1>Administración de Timbres</h1><small>Cuenta comercial por emisor y ambiente fiscal</small></div><a class="button" href="<?= esc(site_url($base_path . '/history') . '?key=' . rawurlencode($key)) ?>">Historial general</a></header>
<?php if ($message): ?><div class="notice ok"><?= esc($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= esc($error) ?></div><?php endif; ?>
<section class="card"><table><thead><tr><th>ID emisor</th><th>RFC / razón social</th><th>Ambiente</th><th>Disponibles</th><th>Reservados</th><th>Estado</th><th>Actualización</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($accounts as $account): $environment=(string)($account['account_environment'] ?? $account['profile_environment'] ?? 'development'); ?>
<tr><td><?= (int)$account['issuer_profile_id'] ?></td><td><strong><?= esc($account['rfc']) ?></strong><br><span class="muted"><?= esc($account['legal_name']) ?></span></td><td><?= esc($environment) ?></td><td class="num"><span class="badge available"><?= number_format((int)$account['available_balance']) ?></span></td><td class="num"><span class="badge reserved"><?= number_format((int)$account['reserved_balance']) ?></span></td><td><span class="badge"><?= esc($account['account_status']) ?></span></td><td><?= esc((string)($account['updated_at'] ?? '—')) ?></td><td><div class="actions">
<a class="button" href="<?= esc(site_url($base_path . '/history/' . (int)$account['issuer_profile_id']) . '?key=' . rawurlencode($key)) ?>">Ver historial</a>
<details><summary class="button">Ajustar timbres</summary><form class="formbox" method="post" action="<?= esc(site_url($base_path . '/adjust') . '?key=' . rawurlencode($key)) ?>">
<?= csrf_field() ?><input type="hidden" name="key" value="<?= esc($key) ?>"><input type="hidden" name="issuer_profile_id" value="<?= (int)$account['issuer_profile_id'] ?>"><input type="hidden" name="environment" value="<?= esc($environment) ?>"><input type="hidden" name="request_id" value="<?= bin2hex(random_bytes(16)) ?>">
<label>Tipo<select name="type" required><option value="credit">Agregar</option><option value="debit">Quitar</option></select></label><label>Cantidad<input name="quantity" type="number" min="1" max="1000000" step="1" required></label><label>Motivo<textarea name="reason" maxlength="1000" required></textarea></label><label>Referencia (opcional)<input name="reference" maxlength="191"></label><button class="submit" type="submit">Registrar ajuste</button>
</form></details></div></td></tr>
<?php endforeach; ?>
<?php if (!$accounts): ?><tr><td colspan="8">No hay perfiles emisores configurados.</td></tr><?php endif; ?>
</tbody></table></section></main></body></html>
