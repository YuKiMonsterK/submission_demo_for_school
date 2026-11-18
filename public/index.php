<?php
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(32));
$role = $_SESSION['role'] ?? '';
$author = '示範作者';
$statuses = ['draft' => '編輯中', 'pending' => '審查中', 'approved' => '已通過'];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirectHome(string $message = ''): never
{
    $_SESSION['message'] = $message;
    header('Location: ./', true, 303);
    exit;
}

function query(PDO $db, string $sql, array $values = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($values);
    return $statement;
}

function tokenField(): void
{
    echo '<input type="hidden" name="token" value="' . h($_SESSION['token']) . '">';
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $_POST['action'] ?? '';
if ($isPost && !hash_equals($_SESSION['token'], (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    exit('表單已失效，請返回首頁重新操作。');
}

if ($isPost && in_array($action, ['author', 'editor'], true)) {
    $_SESSION['role'] = $action;
    redirectHome();
}

$page = $_GET['page'] ?? 'list';
$id = filter_input($isPost ? INPUT_POST : INPUT_GET, 'id', FILTER_VALIDATE_INT);
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error = '';
$rows = [];
$returnReason = '';
$article = ['category' => '', 'title' => '', 'body' => ''];

try {
    if ($role !== '') {
        $dsn = getenv('SUBMISSION_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=submission_demo';
        $db = new PDO($dsn, getenv('SUBMISSION_DB_USER') ?: 'postgres', getenv('SUBMISSION_DB_PASSWORD') ?: '');

        if ($isPost) {
            // 同一條 SQL 同時檢查稿件目前的狀態並完成更新。
            if ($role === 'author' && in_array($action, ['create', 'update'], true)) {
                foreach (array_keys($article) as $field) {
                    $value = $_POST[$field] ?? '';
                    $article[$field] = is_string($value) ? trim($value) : '';
                }
                $page = 'form';
                if ($action === 'create') {
                    $id = null;
                } elseif (!$id) {
                    redirectHome('找不到指定的稿件。');
                }
                if (preg_match('/[^\s\p{Z}]/u', $article['category']) !== 1
                    || $article['title'] === '' || $article['body'] === '') {
                    $error = '請填寫類別、標題與文章內容。';
                } else {
                    $values = array_values($article);
                    $values[] = $author;
                    if ($action === 'create') {
                        query($db, 'INSERT INTO submissions (category, title, body, author) VALUES (?, ?, ?, ?)', $values);
                        redirectHome('稿件已新增。');
                    }
                    $values[] = $id;
                    $result = query($db, "UPDATE submissions SET category = ?, title = ?, body = ?
                        WHERE author = ? AND id = ? AND status = 'draft'", $values);
                    redirectHome($result->rowCount() ? '稿件已儲存。' : '這篇稿件目前無法編輯。');
                }
            } elseif ($role === 'author' && $id && $action === 'delete') {
                $result = query($db, "DELETE FROM submissions WHERE id = ? AND author = ? AND status = 'draft'", [$id, $author]);
                redirectHome($result->rowCount() ? '稿件已刪除。' : '這篇稿件目前無法刪除。');
            } elseif ($role === 'author' && $id && $action === 'submit') {
                $result = query($db, "UPDATE submissions SET status = 'pending'
                    WHERE id = ? AND author = ? AND status = 'draft'", [$id, $author]);
                redirectHome($result->rowCount() ? '稿件已送出。' : '這篇稿件目前無法送出。');
            } elseif ($role === 'editor' && $id && in_array($action, ['approve', 'return'], true)) {
                $reasonInput = $_POST['return_reason'] ?? '';
                $returnReason = is_string($reasonInput) ? trim($reasonInput) : '';
                if ($action === 'return' && (preg_match('/[^\s\p{Z}]/u', $returnReason) !== 1)) {
                    http_response_code(422);
                    $error = '請填寫退回理由。';
                    $page = 'review';
                } else {
                    if ($action === 'return') {
                        $result = query($db, "UPDATE submissions SET status = 'draft', return_reason = ?
                            WHERE id = ? AND status = 'pending'", [$returnReason, $id]);
                        $success = '稿件已退回給作者。';
                    } else {
                        $result = query($db, "UPDATE submissions SET status = 'approved'
                            WHERE id = ? AND status = 'pending'", [$id]);
                        $success = '稿件已通過。';
                    }
                    redirectHome($result->rowCount() ? $success : '這篇稿件已離開待審查清單。');
                }
            } else {
                http_response_code(403);
                exit('目前角色無法執行這項操作。');
            }
        }
        if ($page === 'form' && $role === 'author') {
            if ($id !== null) {
                $savedArticle = query($db, "SELECT category, title, body, return_reason FROM submissions
                    WHERE id = ? AND author = ? AND status = 'draft'", [$id, $author])->fetch(PDO::FETCH_ASSOC);
                if (!$savedArticle) {
                    redirectHome('這篇稿件目前無法編輯。');
                }
                $returnReason = $savedArticle['return_reason'] ?? '';
                if (!$isPost) {
                    $article = $savedArticle;
                }
            }
        } elseif ($page === 'review' && $role === 'editor') {
            $article = query($db, "SELECT author, category, title, body FROM submissions
                WHERE id = ? AND status = 'pending'", [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$article) {
                redirectHome('找不到待審查的稿件。');
            }
        } else {
            $page = 'list';
            $statement = $role === 'author'
                ? query($db, 'SELECT id, category, title, status, return_reason FROM submissions WHERE author = ? ORDER BY id DESC', [$author])
                : query($db, "SELECT id, author, category, title FROM submissions WHERE status = 'pending' ORDER BY id");
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    $page = 'error';
    $error = '無法讀寫資料庫。請確認 PostgreSQL 已啟動、連線設定正確，且已匯入 schema.sql。';
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>投稿審查</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <a class="brand" href="./">投稿審查</a>
    <form method="post" action="./" aria-label="切換示範角色">
        <?php tokenField(); ?>
        <button name="action" value="author" <?= $role === 'author' ? 'aria-pressed="true"' : '' ?>>作者</button>
        <button name="action" value="editor" <?= $role === 'editor' ? 'aria-pressed="true"' : '' ?>>編輯</button>
    </form>
</header>
<main>
    <?php if ($message !== ''): ?><p class="notice" role="status"><?= h($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>

    <?php if ($role === ''): ?>
        <h1>選擇示範角色</h1>
        <p>從上方選擇「作者」管理稿件，或選擇「編輯」審查投稿。</p>
    <?php elseif ($page === 'list'): ?>
        <div class="heading">
            <h1><?= $role === 'author' ? '我的稿件' : '待審查稿件' ?></h1>
            <?php if ($role === 'author'): ?><a class="primary button" href="?page=form">新增稿件</a><?php endif; ?>
        </div>
        <?php if (!$rows): ?>
            <p><?= $role === 'author' ? '目前沒有稿件。' : '目前沒有待審查的稿件。' ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <?php if ($role === 'editor'): ?><th scope="col">作者</th><?php endif; ?>
                        <th scope="col">類別</th><th scope="col">標題</th>
                        <?php if ($role === 'author'): ?><th scope="col">審查狀態</th><?php endif; ?>
                        <th scope="col">操作</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php if ($role === 'editor'): ?><td><?= h($row['author']) ?></td><?php endif; ?>
                            <td class="category"><?= h($row['category']) ?></td>
                            <td class="title"><?= h($row['title']) ?></td>
                            <?php if ($role === 'author'): ?>
                                <td>
                                    <?= h($statuses[$row['status']]) ?>
                                    <?php if ($row['status'] === 'draft' && $row['return_reason'] !== null): ?>
                                        <span class="returned-label">曾被退回</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($role === 'editor'): ?>
                                    <a href="?page=review&amp;id=<?= (int) $row['id'] ?>">查看</a>
                                <?php elseif ($row['status'] === 'draft'): ?>
                                    <form class="actions" method="post" action="./">
                                        <?php tokenField(); ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <a class="button" href="?page=form&amp;id=<?= (int) $row['id'] ?>">編輯</a>
                                        <button class="primary" name="action" value="submit">送出</button>
                                        <button name="action" value="delete" onclick="return confirm('確定刪除這篇稿件？')">刪除</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($page === 'form'): ?>
        <h1><?= $id ? '編輯稿件' : '新增稿件' ?></h1>
        <?php if ($returnReason !== ''): ?>
            <aside class="return-note">
                <strong>最近一次退回理由</strong>
                <div><?= h($returnReason) ?></div>
            </aside>
        <?php endif; ?>
        <form class="article-form" method="post" action="./">
            <?php tokenField(); ?>
            <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <label for="category">類別</label>
            <input id="category" name="category" value="<?= h($article['category']) ?>" placeholder="例如校園活動" required>
            <label for="title">標題</label>
            <input id="title" name="title" value="<?= h($article['title']) ?>" required>
            <label for="body">文章內容</label>
            <textarea id="body" name="body" rows="10" required><?= h($article['body']) ?></textarea>
            <div class="actions">
                <button class="primary" name="action" value="<?= $id ? 'update' : 'create' ?>">儲存</button>
                <a class="button" href="./">返回</a>
            </div>
        </form>
    <?php elseif ($page === 'review'): ?>
        <h1 class="title"><?= h($article['title']) ?></h1>
        <p class="meta">作者 <?= h($article['author']) ?> ・ <?= h($article['category']) ?></p>
        <article><?= h($article['body']) ?></article>
        <form class="article-form" method="post" action="./">
            <?php tokenField(); ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label for="return_reason">退回理由（退回時必填）</label>
            <textarea id="return_reason" name="return_reason" rows="3"><?= h($returnReason) ?></textarea>
            <div class="actions">
                <button class="primary" name="action" value="approve">通過</button>
                <button class="danger" name="action" value="return">退回稿件</button>
                <a class="back-link" href="./">返回清單</a>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
