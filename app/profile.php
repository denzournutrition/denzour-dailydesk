<?php
defined('DD') or exit;

/* Employee profiles: photo, phone, city, bio, joining date, manager. */

function profile_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        phone TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        bio TEXT NOT NULL DEFAULT '',
        emp_code TEXT NOT NULL DEFAULT '',
        joined_on TEXT NOT NULL DEFAULT '',
        manager_id INTEGER,
        photo TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}

function profile_get(int $uid): array
{
    $st = db()->prepare('SELECT * FROM profiles WHERE user_id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: ['user_id' => $uid, 'phone' => '', 'city' => '', 'bio' => '', 'emp_code' => '', 'joined_on' => '', 'manager_id' => null, 'photo' => '', 'updated_at' => ''];
}

function profile_save(int $uid, array $f): void
{
    $cur = profile_get($uid);
    $row = array_merge($cur, $f);
    db()->prepare('INSERT INTO profiles (user_id,phone,city,bio,emp_code,joined_on,manager_id,photo,updated_at) VALUES (?,?,?,?,?,?,?,?,?)
        ON CONFLICT(user_id) DO UPDATE SET phone=excluded.phone, city=excluded.city, bio=excluded.bio, emp_code=excluded.emp_code,
        joined_on=excluded.joined_on, manager_id=excluded.manager_id, photo=excluded.photo, updated_at=excluded.updated_at')
        ->execute([$uid, $row['phone'], $row['city'], $row['bio'], $row['emp_code'], $row['joined_on'], $row['manager_id'] ?: null, $row['photo'], date('Y-m-d H:i:s')]);
}

function avatar_dir(): string
{
    $d = dirname(__DIR__) . '/data/avatars';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

/** Round avatar: the photo when there is one, otherwise the initials. */
function avatar_html(array $u, int $size = 38, ?string $photo = null): string
{
    if ($photo === null) {
        static $cache = [];
        $id = (int)$u['id'];
        if (!isset($cache[$id])) {
            $st = db()->prepare('SELECT photo, updated_at FROM profiles WHERE user_id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            $cache[$id] = $r && $r['photo'] !== '' ? $r['photo'] . '|' . $r['updated_at'] : '';
        }
        $photo = $cache[$id];
    }
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * 0.36, 1) . 'px';
    if ($photo !== '') {
        $v = substr(md5($photo), 0, 8);
        return '<img class="avatar avatar--img" style="' . $style . '" src="' . h(url('avatar', ['id' => (int)$u['id'], 'v' => $v])) . '" alt="" loading="lazy">';
    }
    return '<span class="avatar" style="' . $style . '" aria-hidden="true">' . h(initials($u['name'])) . '</span>';
}

/** Validate and store an uploaded photo (square, 480px JPEG). Returns [filename|null, error|null]. */
function profile_photo_store(int $uid, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return [null, null]; }
    if ($file['error'] !== UPLOAD_ERR_OK) { return [null, 'The photo could not be uploaded. Try a smaller picture.']; }
    if ($file['size'] > 5 * 1024 * 1024) { return [null, 'Photo is too big. Keep it under 5 MB.']; }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [null, 'Use a JPG, PNG or WebP picture.'];
    }
    if (!function_exists('imagecreatetruecolor')) { return [null, 'This server cannot process photos (GD missing).']; }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        default        => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) { return [null, 'That picture could not be read.']; }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($file['tmp_name']);
        $o = (int)($ex['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) { $rot = imagerotate($src, $angle, 0); if ($rot) { $src = $rot; } }
    }
    $w = imagesx($src); $h = imagesy($src); $s = min($w, $h);
    $dst = imagecreatetruecolor(480, 480);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), 480, 480, $s, $s);
    $name = $uid . '.jpg';
    $ok = imagejpeg($dst, avatar_dir() . '/' . $name, 86);
    return $ok ? [$name, null] : [null, 'The photo could not be saved.'];
}

function can_view_profile(array $viewer, int $targetId): bool
{
    return (int)$viewer['id'] === $targetId || in_array($viewer['role'], ['admin', 'ceo'], true);
}

/** Phone numbers: keep digits, spaces, + - ( ). */
function clean_phone(string $p): string
{
    return mb_substr(trim(preg_replace('/[^0-9+\-\s()]/', '', $p) ?? ''), 0, 20);
}
