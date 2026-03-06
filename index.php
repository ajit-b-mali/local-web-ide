<?php
// --- PHP BACKEND FOR WEB IDE ---
session_start();

// --- Configuration ---
// Files that users are strictly NOT allowed to open or edit
$RESTRICTED_FILES = ['.env', 'config.php', 'wp-config.php', 'secret.txt', 'index.php']; 
$BASE_DIR = str_replace('\\', '/', __DIR__ . '/folder'); // Directory to serve files from
$LOCK_DB = $BASE_DIR . '/.ide-locks.json'; // Centralized lock registry

// Simulate a logged-in user for concurrency locking (in production, use your real auth session)
if (!isset($_SESSION['ide_user'])) {
    $_SESSION['ide_user'] = 'Dev_' . rand(100, 999);
}
$CURRENT_USER = $_SESSION['ide_user'];

// --- Centralized Lock Manager ---
function getActiveLocks() {
    global $LOCK_DB;
    if (!file_exists($LOCK_DB)) return [];
    
    $locks = json_decode(file_get_contents($LOCK_DB), true);
    if (!is_array($locks)) return [];
    
    $activeLocks = [];
    $now = time();
    // Filter out expired locks (older than 60 seconds)
    foreach ($locks as $file => $data) {
        if ($now - $data['time'] < 60) {
            $activeLocks[$file] = $data;
        }
    }
    return $activeLocks;
}

function saveActiveLocks($locks) {
    global $LOCK_DB;
    file_put_contents($LOCK_DB, json_encode($locks));
}

// --- API Handler ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    
    $file = $_POST['file'] ?? $_GET['file'] ?? '';
    
    $isSafe = true;
    $reqFile = false;

    // Security: Robust string-based path resolution
    if ($file) {
        $cleanPath = str_replace('\\', '/', $file);
        if (strpos($cleanPath, '../') !== false) {
            $isSafe = false;
        } else {
            $reqFile = rtrim($BASE_DIR, '/\\') . '/' . ltrim($cleanPath, '/');
            if (in_array(basename($cleanPath), $RESTRICTED_FILES)) {
                $isSafe = false;
            }
        }
    }

    if ($action === 'list') {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($BASE_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $fileinfo) {
            $pathname = $fileinfo->getPathname();
            $relPath = substr($pathname, strlen($BASE_DIR) + 1);
            $relPath = str_replace('\\', '/', $relPath);

            // Hide dotfiles/dotfolders (including our new .ide-locks.json)
            $isHidden = false;
            foreach (explode('/', $relPath) as $part) {
                if (str_starts_with($part, '.')) {
                    $isHidden = true;
                    break;
                }
            }

            if ($isHidden || in_array(basename($relPath), $RESTRICTED_FILES)) continue;

            $files[] = [
                'path' => $relPath,
                'isDir' => $fileinfo->isDir()
            ];
        }
        
        usort($files, function($a, $b) {
            if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
            return strcasecmp($a['path'], $b['path']);
        });
        
        echo json_encode(['status' => 'success', 'files' => $files, 'user' => $CURRENT_USER]);
        exit;
    }

    if (!$isSafe || !$reqFile) {
        echo json_encode(['status' => 'error', 'message' => 'Access denied or file invalid.']);
        exit;
    }

    if ($action === 'load') {
        $content = file_exists($reqFile) && !is_dir($reqFile) ? file_get_contents($reqFile) : "/* New File */";
        
        // Centralized Concurrency Check
        $locks = getActiveLocks();
        $lockedBy = $locks[$reqFile]['user'] ?? null;
        
        // Claim lock if free
        if (!$lockedBy || $lockedBy === $CURRENT_USER) {
            $locks[$reqFile] = ['user' => $CURRENT_USER, 'time' => time()];
            saveActiveLocks($locks);
            $lockedBy = $CURRENT_USER;
        }

        echo json_encode([
            'status' => 'success',
            'content' => $content,
            'lockedBy' => $lockedBy,
            'canEdit' => ($lockedBy === $CURRENT_USER)
        ]);
        exit;
    }

    if ($action === 'save') {
        $locks = getActiveLocks();
        $lockedBy = $locks[$reqFile]['user'] ?? null;
        
        if (!$lockedBy || $lockedBy === $CURRENT_USER) {
            file_put_contents($reqFile, $_POST['content']);
            // Renew lock
            $locks[$reqFile] = ['user' => $CURRENT_USER, 'time' => time()];
            saveActiveLocks($locks);
            echo json_encode(['status' => 'success', 'message' => 'File saved successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File is currently locked by another user.']);
        }
        exit;
    }

    if ($action === 'heartbeat') {
        $locks = getActiveLocks();
        $lockedBy = $locks[$reqFile]['user'] ?? null;
        
        if (!$lockedBy || $lockedBy === $CURRENT_USER) {
            $locks[$reqFile] = ['user' => $CURRENT_USER, 'time' => time()];
            saveActiveLocks($locks);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'unlock') {
        $locks = getActiveLocks();
        if (isset($locks[$reqFile]) && $locks[$reqFile]['user'] === $CURRENT_USER) {
            unset($locks[$reqFile]);
            saveActiveLocks($locks);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'create') {
        $type = $_POST['type'] ?? 'file';
        if (file_exists($reqFile)) {
            echo json_encode(['status' => 'error', 'message' => 'Path already exists.']); exit;
        }
        $dir = dirname($reqFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        if ($type === 'folder') mkdir($reqFile, 0777, true);
        else file_put_contents($reqFile, '');
        
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'rename') {
        $newFile = $_POST['newFile'] ?? '';
        $cleanNew = str_replace('\\', '/', $newFile);
        if (!$newFile || strpos($cleanNew, '../') !== false || in_array(basename($cleanNew), $RESTRICTED_FILES)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid new name.']); exit;
        }
        $reqNewFile = rtrim($BASE_DIR, '/\\') . '/' . ltrim($cleanNew, '/');
        
        // Block rename if file is currently locked
        $locks = getActiveLocks();
        if (isset($locks[$reqFile])) {
             echo json_encode(['status' => 'error', 'message' => 'Cannot rename: File is currently being edited.']); exit;
        }
        
        $dir = dirname($reqNewFile);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        rename($reqFile, $reqNewFile);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'delete') {
        echo json_encode(['status' => 'error', 'message' => 'Action disabled: Deletion is restricted to managers.']); exit;
    }

    if ($action === 'git') {
        $log = shell_exec('git log -n 10 --pretty=format:"%h - %an, %ar : %s" -- ' . escapeshellarg($reqFile));
        echo json_encode([
            'status' => 'success',
            'log' => $log ? $log : 'No git history available for this file. Ensure directory is a git repo.'
        ]);
        exit;
    }

    if ($action === 'save') {
        $locks = getActiveLocks();
        $lockedBy = $locks[$reqFile]['user'] ?? null;
        
        if (!$lockedBy || $lockedBy === $CURRENT_USER) {
            // 1. Local File History: Backup before overwrite
            $historyDir = $BASE_DIR . '/.ide-history';
            if (!is_dir($historyDir)) mkdir($historyDir, 0777, true);
            
            if (file_exists($reqFile)) {
                // Save backup with hash and timestamp
                $backupName = md5($reqFile) . '_' . time() . '.bak';
                copy($reqFile, $historyDir . '/' . $backupName);
            }

            // Save the new content
            file_put_contents($reqFile, $_POST['content']);
            
            // Renew lock
            $locks[$reqFile] = ['user' => $CURRENT_USER, 'time' => time()];
            saveActiveLocks($locks);

            // 2. Offline Linter: Check PHP syntax
            $lintError = null;
            $lintLine = null;
            if (pathinfo($reqFile, PATHINFO_EXTENSION) === 'php') {
                exec('php -l ' . escapeshellarg($reqFile) . ' 2>&1', $output, $return_var);
                if ($return_var !== 0) {
                    $lintStr = implode(" ", $output);
                    $lintError = $lintStr;
                    // Extract line number via regex
                    if (preg_match('/on line (\d+)/', $lintStr, $matches)) {
                        $lintLine = $matches[1];
                    }
                }
            }

            echo json_encode([
                'status' => 'success', 
                'message' => 'File saved successfully.',
                'lintError' => $lintError,
                'lintLine' => $lintLine
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File is currently locked by another user.']);
        }
        exit;
    }

    if ($action === 'search_files') {
        $query = $_GET['q'] ?? '';
        if (strlen($query) < 2) {
            echo json_encode(['status' => 'success', 'results' => []]); exit;
        }

        $results = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($BASE_DIR));
        
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $path = $fileinfo->getPathname();
                // Skip hidden files/directories and images
                if (strpos($path, '/.') !== false || preg_match('/\.(jpg|png|gif|zip)$/i', $path)) continue;
                
                $content = file_get_contents($path);
                if (stripos($content, $query) !== false) {
                    $relPath = str_replace('\\', '/', substr($path, strlen($BASE_DIR) + 1));
                    // Get a snippet of the match
                    preg_match('/^.*?' . preg_quote($query, '/') . '.*$/mi', $content, $matches);
                    $results[] = [
                        'path' => $relPath,
                        'snippet' => trim($matches[0] ?? '')
                    ];
                }
            }
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }

    if ($action === 'stream') {
        if (!$isSafe || !$reqFile || !file_exists($reqFile) || is_dir($reqFile)) {
            header('HTTP/1.0 404 Not Found');
            exit;
        }
        // Get the actual MIME type of the image
        $mime = mime_content_type($reqFile);
        if (!$mime) $mime = 'application/octet-stream';
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($reqFile));
        readfile($reqFile);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown endpoint']);
    exit;
}
// --- END PHP BACKEND ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Web IDE</title>
    
    <!-- CSS & Icons -->
    <script src="tailwind.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- FZF-style fuzzy search library -->
    <script src="https://cdn.jsdelivr.net/npm/fuse.js/dist/fuse.min.js"></script>
    <!-- JS Beautify for Auto-Formatting -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.14.9/beautify.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.14.9/beautify-html.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/js-beautify/1.14.9/beautify-css.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --editor-fs: 12px;
        }
        body { font-family: 'Inter', sans-serif; }
        .cm-editor { 
            height: 100%; 
            outline: none !important; 
            font-family: 'Fira Code', monospace;
            font-size: var(--editor-fs) !important;
        }
        .cm-scroller { overflow: auto; }
        /* Scrollbar styling */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #52525b; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #71717a; }
        /* Context Menu Styles */
        #context-menu {
            display: none;
            position: absolute;
            z-index: 1000;
            background: #18181b; /* zinc-900 */
            border: 1px solid #27272a; /* zinc-800 */
            border-radius: 6px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
            min-width: 150px;
            padding: 4px;
        }
        .context-item {
            padding: 6px 10px;
            font-size: 12px;
            color: #d4d4d8;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }
        .context-item:hover { background: #3f3f46; color: white; }
        .context-item i { width: 14px; height: 14px; margin-right: 8px; }
        .split-view { display: flex; width: 100%; height: 100%; }
        .split-editor { flex: 1; min-width: 0; border-right: 1px solid #27272a; }
        .split-preview { flex: 1; min-width: 0; background: #18181b; padding: 20px; overflow-y: auto; color: #e4e4e7; }
        
        /* Basic Markdown Styles */
        .markdown-body h1, .markdown-body h2 { border-bottom: 1px solid #3f3f46; padding-bottom: 0.3em; margin-bottom: 16px; margin-top: 24px;}
        .markdown-body h1 { font-size: 2em; }
        .markdown-body h2 { font-size: 1.5em; }
        .markdown-body p { margin-bottom: 16px; line-height: 1.6; }
        .markdown-body code { background: #27272a; padding: 0.2em 0.4em; border-radius: 3px; font-family: monospace; }
        .markdown-body pre { background: #09090b; padding: 16px; border-radius: 6px; overflow-x: auto; }
        .markdown-body pre code { background: transparent; padding: 0; }
        .markdown-body ul { list-style-type: disc; padding-left: 2em; margin-bottom: 16px; }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- CodeMirror 6 Dependencies via Import Map -->
    <script type="importmap">
    {
        "imports": {
            "@codemirror/state": "https://esm.sh/@codemirror/state",
            "@codemirror/view": "https://esm.sh/@codemirror/view",
            "@codemirror/commands": "https://esm.sh/@codemirror/commands",
            "@codemirror/search": "https://esm.sh/@codemirror/search",
            "@codemirror/autocomplete": "https://esm.sh/@codemirror/autocomplete",
            "@codemirror/lint": "https://esm.sh/@codemirror/lint",
            "@codemirror/language": "https://esm.sh/@codemirror/language",
            "@codemirror/theme-one-dark": "https://esm.sh/@codemirror/theme-one-dark",
            "codemirror": "https://esm.sh/codemirror",
            "@codemirror/lang-javascript": "https://esm.sh/@codemirror/lang-javascript",
            "@codemirror/lang-html": "https://esm.sh/@codemirror/lang-html",
            "@codemirror/lang-css": "https://esm.sh/@codemirror/lang-css",
            "@codemirror/lang-php": "https://esm.sh/@codemirror/lang-php",
            "@codemirror/lang-markdown": "https://esm.sh/@codemirror/lang-markdown",
            "@replit/codemirror-vim": "https://esm.sh/@replit/codemirror-vim",
            "fuse.js": "https://esm.sh/fuse.js",
            "@emmetio/codemirror6-plugin": "https://esm.sh/@emmetio/codemirror6-plugin"
        }
    }
    </script>
</head>
<body class="bg-zinc-900 text-zinc-300 h-screen flex overflow-hidden selection:bg-indigo-500/30">

    <!-- Sidebar: File Explorer -->
    <aside class="w-64 bg-zinc-950 border-r border-zinc-800 flex flex-col z-20 shadow-xl">
        <div class="h-14 flex items-center px-4 border-b border-zinc-800 bg-zinc-950/50 shrink-0">
            <i data-lucide="terminal-square" class="w-5 h-5 mr-2 text-indigo-400"></i>
            <span class="font-semibold text-zinc-100 tracking-wide">Company IDE</span>
        </div>
        
        <div class="px-3 py-2 text-xs font-semibold text-zinc-500 uppercase tracking-wider mt-2 flex justify-between items-center shrink-0">
            <span>Explorer</span>
            <div class="flex items-center space-x-1">
                <button id="new-file-btn" class="hover:text-zinc-300 transition-colors p-1" title="New File"><i data-lucide="file-plus" class="w-3.5 h-3.5"></i></button>
                <button id="new-folder-btn" class="hover:text-zinc-300 transition-colors p-1" title="New Folder"><i data-lucide="folder-plus" class="w-3.5 h-3.5"></i></button>
                <button id="refresh-files" class="hover:text-zinc-300 transition-colors p-1" title="Refresh"><i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i></button>
            </div>
        </div>

        <div class="px-3 mb-2 shrink-0 flex flex-col space-y-2">
            <input type="text" id="file-search" placeholder="Fuzzy file search..." class="w-full bg-zinc-900 border border-zinc-800 rounded text-xs px-2 py-1.5 text-zinc-300 outline-none focus:border-indigo-500 transition-colors">
            
            <div class="flex space-x-2">
                <input type="text" id="global-search" placeholder="Search inside files..." class="w-full bg-zinc-900 border border-zinc-800 rounded text-xs px-2 py-1.5 text-zinc-300 outline-none focus:border-indigo-500 transition-colors">
                <button id="run-global-search" class="bg-zinc-800 hover:bg-zinc-700 px-2 rounded border border-zinc-700 text-zinc-300"><i data-lucide="search" class="w-3.5 h-3.5"></i></button>
            </div>
        </div>
        
        <div id="file-list" class="flex-1 overflow-y-auto px-2 py-1 space-y-0.5 text-sm">
            <!-- Files injected here -->
        </div>
        
        <div class="p-3 text-xs text-zinc-500 border-t border-zinc-800 flex items-center shrink-0">
            <i data-lucide="user" class="w-3.5 h-3.5 mr-1.5"></i>
            <span id="current-user-display">Loading User...</span>
        </div>
    </aside>

    <!-- Main IDE Area -->
    <main class="flex-1 flex flex-col relative min-w-0">
        <!-- Top Toolbar -->
        <header class="h-14 bg-zinc-900 border-b border-zinc-800 flex items-center justify-between px-4 z-10 shadow-sm shrink-0">
            <div class="flex items-center space-x-4 min-w-0">
                <div class="flex items-center bg-zinc-800/50 rounded-md px-3 py-1.5 border border-zinc-700/50">
                    <i data-lucide="file-code-2" class="w-4 h-4 text-zinc-400 mr-2"></i>
                    <span id="current-file-name" class="text-sm font-medium text-zinc-200 truncate max-w-[200px]">No file selected</span>
                </div>
                
                <!-- Concurrency Lock Banner -->
                <div id="lock-banner" class="hidden items-center px-2.5 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-medium">
                    <i data-lucide="lock" class="w-3 h-3 mr-1.5"></i>
                    <span id="lock-text">Locked by Another User</span>
                </div>
            </div>

            <div class="flex items-center space-x-3">
                
                <!-- Font Size Controls -->
                <div class="flex items-center space-x-1 bg-zinc-800 border border-zinc-700 rounded-md px-1 py-1 text-zinc-300" title="Adjust Font Size">
                    <button id="font-dec" class="p-1 hover:text-white hover:bg-zinc-700 rounded transition-colors"><i data-lucide="minus" class="w-3.5 h-3.5"></i></button>
                    <span id="font-size-display" class="text-[11px] font-mono select-none w-8 text-center">12px</span>
                    <button id="font-inc" class="p-1 hover:text-white hover:bg-zinc-700 rounded transition-colors"><i data-lucide="plus" class="w-3.5 h-3.5"></i></button>
                </div>

                <!-- Theme Selector -->
                <div class="flex items-center space-x-2">
                    <i data-lucide="palette" class="w-4 h-4 text-zinc-500"></i>
                    <select id="theme-select" class="bg-zinc-800 text-xs text-zinc-200 border border-zinc-700 rounded-md px-2 py-1.5 outline-none focus:border-indigo-500 cursor-pointer">
                        <option value="onedark">One Dark</option>
                        <option value="dracula">Dracula</option>
                        <option value="monokai">Monokai Dark</option>
                        <option value="light">Light Mode</option>
                    </select>
                </div>

                <!-- Format Button -->
                <button id="format-btn" class="flex items-center px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-md text-xs font-medium transition-colors text-zinc-300" title="Format Code (Shift+Alt+F)">
                    <i data-lucide="align-left" class="w-3.5 h-3.5 mr-1.5"></i>
                    Format
                </button>

                <!-- Preview Toggle (for Markdown) -->
                <button id="preview-btn" class="flex items-center px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-md text-xs font-medium transition-colors text-zinc-300 hidden" title="Toggle Preview">
                    <i data-lucide="columns" class="w-3.5 h-3.5 mr-1.5"></i>
                    Preview
                </button>

                <!-- Vim Toggle -->
                <button id="vim-toggle" class="flex items-center px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-md text-xs font-medium transition-colors text-zinc-300">
                    <i data-lucide="keyboard" class="w-3.5 h-3.5 mr-1.5"></i>
                    Vim: Off
                </button>

                <!-- Save Button -->
                <button id="save-btn" class="flex items-center px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium transition-all shadow-sm opacity-50 cursor-not-allowed">
                    <i data-lucide="save" class="w-4 h-4 mr-1.5"></i>
                    Save (Ctrl+S)
                </button>
            </div>
        </header>

        <!-- Tabs Bar -->
        <div id="tabs-bar" class="h-9 bg-zinc-900 border-b border-zinc-800 flex overflow-x-auto shrink-0 space-x-1 px-2 items-end hidden">
            <!-- Tabs injected here -->
        </div>

        <!-- Editor Container -->
        <div id="editor-container" class="flex-1 relative overflow-hidden bg-zinc-900">
            <div id="empty-state" class="absolute inset-0 flex flex-col items-center justify-center text-zinc-500">
                <i data-lucide="code" class="w-16 h-16 mb-4 opacity-20"></i>
                <p>Select a file from the explorer to start editing.</p>
            </div>
        </div>

        <!-- Bottom Panel (Git & Terminal) -->
        <div id="bottom-panel" class="h-48 bg-zinc-950 border-t border-zinc-800 flex flex-col hidden absolute bottom-0 left-0 right-0 z-20 shadow-2xl transition-transform">
            <div class="h-8 border-b border-zinc-800 bg-zinc-900 flex items-center justify-between px-3">
                <div class="flex items-center text-xs font-medium text-zinc-400">
                    <i data-lucide="git-commit" class="w-3.5 h-3.5 mr-1.5"></i> Git History
                </div>
                <button id="close-bottom-panel" class="text-zinc-500 hover:text-zinc-300">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
            <pre id="git-log-output" class="flex-1 p-3 text-xs text-green-400 font-mono overflow-y-auto whitespace-pre-wrap"></pre>
        </div>

        <!-- Status Bar -->
        <footer class="h-7 bg-indigo-600/90 text-white flex items-center justify-between px-3 text-[11px] font-medium z-10 shrink-0">
            <div class="flex items-center space-x-4">
                <span id="status-mode">NORMAL</span>
                <span id="status-lang">No Language</span>
            </div>
            <div class="flex items-center space-x-3">
                <button id="git-btn" class="hover:text-indigo-200 flex items-center transition-colors">
                    <i data-lucide="git-branch" class="w-3 h-3 mr-1"></i> View Git Log
                </button>
                <span id="status-sync">Synced</span>
            </div>
        </footer>
    </main>

    <div id="context-menu">
        <div class="context-item" id="ctx-rename"><i data-lucide="edit-2"></i> Rename</div>
    </div>

    <!-- App Logic (ES Modules) -->
    <script type="module">
        import { EditorState, Compartment } from "@codemirror/state";
        import { EditorView, keymap, lineNumbers } from "@codemirror/view";
        import { basicSetup } from "codemirror";
        import { indentWithTab } from "@codemirror/commands";
        
        // Autocomplete & Linting imports
        import { autocompletion, closeBrackets } from "@codemirror/autocomplete";
        import { lintGutter } from "@codemirror/lint";

        // Language Parsing
        import { javascript } from "@codemirror/lang-javascript";
        import { html } from "@codemirror/lang-html";
        import { css } from "@codemirror/lang-css";
        import { php } from "@codemirror/lang-php";
        import { markdown } from "@codemirror/lang-markdown";
        
        // Plugins & Themes
        import { vim, Vim } from "@replit/codemirror-vim";
        import { oneDark } from "@codemirror/theme-one-dark";
        import Fuse from "fuse.js";
        import { abbreviationTracker, expandAbbreviation } from "@emmetio/codemirror6-plugin";

        // --- Custom Themes Setup ---
        const draculaTheme = EditorView.theme({
            "&": { color: "#f8f8f2", backgroundColor: "#282a36" },
            ".cm-content": { caretColor: "#f8f8f0" },
            "&.cm-focused .cm-cursor": { borderLeftColor: "#f8f8f0" },
            "&.cm-focused .cm-selectionBackground, ::selection": { backgroundColor: "#44475a" },
            ".cm-gutters": { backgroundColor: "#282a36", color: "#6272a4", border: "none" },
        }, {dark: true});

        const monokaiTheme = EditorView.theme({
            "&": { color: "#f8f8f2", backgroundColor: "#272822" },
            ".cm-content": { caretColor: "#f8f8f0" },
            "&.cm-focused .cm-cursor": { borderLeftColor: "#f8f8f0" },
            "&.cm-focused .cm-selectionBackground, ::selection": { backgroundColor: "#49483e" },
            ".cm-gutters": { backgroundColor: "#272822", color: "#75715e", border: "none" },
        }, {dark: true});

        const lightTheme = EditorView.theme({
            "&": { color: "#333", backgroundColor: "#ffffff" },
            ".cm-gutters": { backgroundColor: "#f3f4f6", color: "#9ca3af", borderRight: "1px solid #e5e7eb" }
        }, {dark: false});

        // Initialize Icons
        lucide.createIcons();

        // --- App State & Multi-Tabs ---
        let currentFile = null;
        let isReadOnly = false;
        let editorView = null;
        let vimModeEnabled = false;
        let heartbeatInterval = null;
        let sessionUser = 'Local_Dev';
        let allFiles = []; // For tree/search caching
        let currentFontSize = 12; // Default font size

        const openTabs = {}; // { [filename]: { dom: HTMLElement, view: EditorView, isReadOnly: boolean, lockedBy: string, isDirty: boolean } }
        let activeTab = null;
        const collapsedFolders = new Set(); // Tracks closed folders

        // CodeMirror Compartments
        const themeCompartment = new Compartment();
        const langCompartment = new Compartment();
        const vimCompartment = new Compartment();
        const readOnlyCompartment = new Compartment();
        const relLineNumbersCompartment = new Compartment();

        // Generates dynamic Vim-style relative line numbers
        function getRelativeLineNumbers() {
            return lineNumbers({
                formatNumber: (lineNo, state) => {
                    // Fallback if state or selection isn't fully ready
                    if (!state || !state.selection || !state.selection.main) return lineNo.toString();
                    
                    const cursorLine = state.doc.lineAt(state.selection.main.head).number;
                    if (lineNo === cursorLine) return lineNo.toString(); // Absolute for current line
                    return Math.abs(cursorLine - lineNo).toString();     // Relative for others
                }
            });
        }

        let isPreviewOpen = false;

        document.getElementById('preview-btn').addEventListener('click', () => {
            if (!currentFile || !editorView) return;
            const container = document.getElementById('editor-container');
            const ext = currentFile.split('.').pop().toLowerCase();
            
            if (isPreviewOpen) {
                // Close preview
                container.classList.remove('split-view');
                openTabs[currentFile].dom.classList.remove('split-editor');
                const existingPreview = document.getElementById('live-preview-pane');
                if (existingPreview) existingPreview.remove();
                isPreviewOpen = false;
            } else {
                // Open preview
                if (!['md', 'html'].includes(ext)) {
                    showToast("Preview is only supported for Markdown and HTML.", "error");
                    return;
                }
                
                container.classList.add('split-view');
                openTabs[currentFile].dom.classList.add('split-editor');
                
                const previewPane = document.createElement('div');
                previewPane.id = 'live-preview-pane';
                previewPane.className = 'split-preview markdown-body';
                container.appendChild(previewPane);
                
                updatePreview();
                isPreviewOpen = true;
            }
        });

        function updatePreview() {
            if (!isPreviewOpen || !currentFile || !editorView) return;
            const ext = currentFile.split('.').pop().toLowerCase();
            const content = editorView.state.doc.toString();
            const pane = document.getElementById('live-preview-pane');
            
            if (ext === 'md') {
                pane.innerHTML = marked.parse(content);
            } else if (ext === 'html') {
                pane.innerHTML = `<iframe srcdoc="${content.replace(/"/g, '&quot;')}" style="width:100%; height:100%; border:none; background:white;"></iframe>`;
            }
        }

        // --- Font Size Controls ---
        document.getElementById('font-inc').addEventListener('click', () => {
            if (currentFontSize < 32) {
                currentFontSize++;
                document.documentElement.style.setProperty('--editor-fs', `${currentFontSize}px`);
                document.getElementById('font-size-display').innerText = `${currentFontSize}px`;
            }
        });

        document.getElementById('font-dec').addEventListener('click', () => {
            if (currentFontSize > 8) {
                currentFontSize--;
                document.documentElement.style.setProperty('--editor-fs', `${currentFontSize}px`);
                document.getElementById('font-size-display').innerText = `${currentFontSize}px`;
            }
        });

        document.getElementById('run-global-search').addEventListener('click', async () => {
            const query = document.getElementById('global-search').value;
            if (query.length < 2) return;
            
            const container = document.getElementById('file-list');
            container.innerHTML = '<div class="p-4 text-xs text-zinc-500 text-center">Searching...</div>';
            
            const res = await api('search_files', { q: query });
            container.innerHTML = `<div class="px-3 py-1 text-xs text-indigo-400 font-semibold mb-2">Matches for "${query}"</div>`;
            
            if (res.status === 'success' && res.results.length > 0) {
                res.results.forEach(match => {
                    const div = document.createElement('div');
                    div.className = 'group flex flex-col py-1.5 px-2 mb-1 rounded cursor-pointer transition-colors bg-zinc-900/50 hover:bg-zinc-800 border border-zinc-800/50';
                    div.onclick = () => window.loadFile(match.path);
                    div.innerHTML = `
                        <div class="text-xs text-zinc-300 font-medium truncate"><i data-lucide="file-text" class="w-3 h-3 inline mr-1 text-indigo-400"></i>${match.path.split('/').pop()}</div>
                        <div class="text-[10px] text-zinc-500 font-mono mt-1 truncate break-all">${match.snippet.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                    `;
                    container.appendChild(div);
                });
            } else {
                container.innerHTML += '<div class="p-4 text-xs text-zinc-500 text-center">No matches found.</div>';
            }
            lucide.createIcons();
        });

        // --- API Communications (Direct Fetch, No Mock) ---
        async function api(action, data = {}) {
            try {
                const formData = new URLSearchParams();
                for (const key in data) formData.append(key, data[key]);
                
                // Determine if this is a POST request
                const isPost = ['save', 'create', 'rename', 'delete'].includes(action);
                
                // FIX: Attach data to URL for ALL GET requests (like 'load', 'list', 'git', 'heartbeat')
                const url = `?api=${action}&` + new URLSearchParams(isPost ? {} : data).toString();
                
                const res = await fetch(url, {
                    method: isPost ? 'POST' : 'GET',
                    body: isPost ? formData : undefined,
                    headers: isPost ? { 'Content-Type': 'application/x-www-form-urlencoded' } : undefined
                });
                
                return await res.json();
            } catch (error) {
                console.error("API Error", error);
                return { status: 'error', message: 'Server unreachable. Ensure XAMPP/PHP is running.' };
            }
        }

        // --- UI Interactions ---
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-10 right-6 px-4 py-2.5 rounded shadow-lg text-sm font-medium text-white transition-all transform translate-y-0 opacity-100 z-50 flex items-center ${type === 'success' ? 'bg-emerald-600' : 'bg-rose-600'}`;
            toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'alert-circle'}" class="w-4 h-4 mr-2"></i> ${message}`;
            document.body.appendChild(toast);
            lucide.createIcons();
            
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // --- File Explorer & Fuzzy Search Engine ---
        let fuse = null;

        async function refreshFileList() {
            const res = await api('list');
            if (res.status === 'success') {
                sessionUser = res.user;
                document.getElementById('current-user-display').innerText = `Logged in as: ${sessionUser}`;
                allFiles = res.files;
                
                fuse = new Fuse(allFiles, { keys: ['path'], threshold: 0.3, includeScore: true });
                renderTree(document.getElementById('file-search').value);

                // FIX: Restore state only after files are loaded
                if (!window.workspaceRestored) {
                    restoreWorkspaceState();
                    window.workspaceRestored = true; // Prevent looping
                }
            } else {
                showToast(res.message, 'error');
            }
        }

        window.toggleFolder = function(folderPath, event) {
            event.stopPropagation();
            if (collapsedFolders.has(folderPath)) collapsedFolders.delete(folderPath);
            else collapsedFolders.add(folderPath);
            renderTree(document.getElementById('file-search').value);
        };

        // Parse flat paths into a structured JSON tree
        function buildTree(files) {
            const root = { name: 'root', path: '', isDir: true, children: {} };
            files.forEach(file => {
                const parts = file.path.split('/');
                let current = root;
                for (let i = 0; i < parts.length; i++) {
                    const part = parts[i];
                    if (!part) continue;
                    const pathSoFar = parts.slice(0, i + 1).join('/');
                    const isLast = (i === parts.length - 1);
                    
                    if (!current.children[part]) {
                        current.children[part] = {
                            name: part,
                            path: pathSoFar,
                            isDir: isLast ? file.isDir : true,
                            children: {}
                        };
                    }
                    current = current.children[part];
                }
            });
            return root;
        }

        // Recursively render the nested DOM elements (Cleaned up for Interns)
        function renderTreeNode(node, container, depth) {
            const children = Object.values(node.children).sort((a, b) => {
                if (a.isDir !== b.isDir) return a.isDir ? -1 : 1;
                return a.name.localeCompare(b.name);
            });

            children.forEach(child => {
                const div = document.createElement('div');
                const row = document.createElement('div');
                row.style.paddingLeft = `${depth * 12 + 4}px`;
                // Removed the 'group' class and hover actions
                row.className = `flex items-center justify-between py-1.5 rounded cursor-pointer transition-colors ${child.path === currentFile ? 'bg-indigo-600/20 text-indigo-300' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200'}`;
                
                let icon = child.isDir ? 'folder' : 'file-code';
                if (!child.isDir) {
                    if (child.name.endsWith('.php')) icon = 'file-code-2';
                    else if (child.name.endsWith('.js')) icon = 'file-json';
                    else if (child.name.endsWith('.css')) icon = 'brush';
                    else if (child.name.endsWith('.html')) icon = 'layout';
                    else if (child.name.endsWith('.md')) icon = 'file-text';
                }
                
                const isCollapsed = collapsedFolders.has(child.path);
                const chevron = child.isDir 
                    ? `<i data-lucide="${isCollapsed ? 'chevron-right' : 'chevron-down'}" class="w-3.5 h-3.5 mr-1 opacity-70 shrink-0"></i>` 
                    : `<span class="w-4.5 mr-1 inline-block"></span>`;

                // Removed the hidden action buttons completely
                row.innerHTML = `
                    <div class="flex items-center flex-1 min-w-0" onclick="${child.isDir ? `window.toggleFolder('${child.path}', event)` : `window.loadFile('${child.path}')`}">
                        ${chevron}
                        <i data-lucide="${icon}" class="w-4 h-4 mr-1.5 opacity-70 shrink-0 ${child.isDir && !isCollapsed ? 'text-indigo-400' : ''}"></i>
                        <span class="truncate">${child.name}</span>
                    </div>
                `;
                
                div.appendChild(row);
                container.appendChild(div);

                if (child.isDir && !isCollapsed) {
                    const childContainer = document.createElement('div');
                    div.appendChild(childContainer);
                    renderTreeNode(child, childContainer, depth + 1);
                }
            });
        }

        // Render an FZF-style flat result list for searching
        function renderFlatNode(file, container) {
            const name = file.path.split('/').pop();
            const div = document.createElement('div');
            div.className = `group flex items-center justify-between py-1.5 px-2 rounded cursor-pointer transition-colors ${file.path === currentFile ? 'bg-indigo-600/20 text-indigo-300' : 'text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200'}`;
            
            let icon = file.isDir ? 'folder' : 'file-code';
            if (!file.isDir) {
                if (name.endsWith('.php')) icon = 'file-code-2';
                else if (name.endsWith('.js')) icon = 'file-json';
                else if (name.endsWith('.css')) icon = 'brush';
                else if (name.endsWith('.html')) icon = 'layout';
                else if (name.endsWith('.md')) icon = 'file-text';
            }

            div.innerHTML = `
                <div class="flex flex-col flex-1 min-w-0" onclick="${file.isDir ? `window.toggleFolder('${file.path}', event)` : `window.loadFile('${file.path}')`}">
                    <div class="flex items-center">
                        <i data-lucide="${icon}" class="w-4 h-4 mr-1.5 opacity-70 shrink-0 text-indigo-400"></i>
                        <span class="truncate font-medium">${name}</span>
                    </div>
                    <span class="text-[10px] text-zinc-500 truncate ml-5 opacity-70">${file.path}</span>
                </div>
            `;
            container.appendChild(div);
        }

        // The main tree renderer that was missing
        function renderTree(searchQuery = '') {
            const container = document.getElementById('file-list');
            container.innerHTML = '';
            
            if (searchQuery && fuse) {
                // FZF Mode: Flat list of fuzzy matches
                const results = fuse.search(searchQuery);
                results.forEach(result => renderFlatNode(result.item, container));
            } else {
                // Explorer Mode: Structured File Tree
                const tree = buildTree(allFiles);
                renderTreeNode(tree, container, 0);
            }
            lucide.createIcons();
        }

        document.getElementById('file-search').addEventListener('input', (e) => renderTree(e.target.value));

        // --- File Operations ---
        window.renameFile = async (oldPath, event) => {
            event.stopPropagation();
            const newName = prompt(`Rename ${oldPath} to:`, oldPath);
            if (!newName || newName === oldPath) return;
            
            const res = await api('rename', { file: oldPath, newFile: newName });
            if (res.status === 'success') {
                showToast("Renamed successfully");
                if (openTabs[oldPath]) window.closeTab(oldPath, new Event('click')); // Close old tab
                refreshFileList();
            } else {
                showToast(res.message, 'error');
            }
        };

        window.deleteFile = async (path, event) => {
            event.stopPropagation();
            showToast("Action disabled: Deletion is restricted to managers.", "error");
        };

        document.getElementById('new-file-btn').addEventListener('click', async () => {
            const name = prompt("Enter new file path (e.g., folder/newfile.js):");
            if (!name) return;
            const res = await api('create', { file: name, type: 'file' });
            if (res.status === 'success') { refreshFileList(); window.loadFile(name); }
            else showToast(res.message, 'error');
        });

        document.getElementById('new-folder-btn').addEventListener('click', async () => {
            const name = prompt("Enter new folder path (e.g., folder/subfolder):");
            if (!name) return;
            const res = await api('create', { file: name, type: 'folder' });
            if (res.status === 'success') refreshFileList();
            else showToast(res.message, 'error');
        });


        // --- Editor & Tabs Management ---
        function getLanguageExt(filename) {
            if (filename.endsWith('.php')) return php();
            if (filename.endsWith('.js')) return javascript();
            if (filename.endsWith('.html')) return html();
            if (filename.endsWith('.css')) return css();
            if (filename.endsWith('.md')) return markdown();
            return javascript(); // fallback
        }

        function getThemeExt(themeName) {
            switch(themeName) {
                case 'onedark': return oneDark;
                case 'dracula': return draculaTheme;
                case 'monokai': return monokaiTheme;
                case 'light': return lightTheme;
                default: return oneDark;
            }
        }

        window.closeTab = function(filename, event) {
            event.stopPropagation();
            
            const tabData = openTabs[filename];
            if (tabData) {
                if (tabData.isDirty && !confirm("You have unsaved changes. Close anyway?")) return;
                
                // UNLOCK the file explicitly for other users
                if (!tabData.isReadOnly) {
                    api('unlock', { file: filename });
                }

                // FIX: Only destroy the view if it's a CodeMirror instance (not an image)
                if (tabData.view) {
                    tabData.view.destroy();
                }
                
                tabData.dom.remove();
                delete openTabs[filename];
            }
            
            if (activeTab === filename) {
                const remaining = Object.keys(openTabs);
                if (remaining.length > 0) {
                    switchToTab(remaining[remaining.length - 1]);
                } else {
                    activeTab = null;
                    editorView = null;
                    currentFile = null;
                    document.getElementById('tabs-bar').classList.add('hidden');
                    document.getElementById('empty-state').classList.remove('hidden');
                    document.getElementById('status-lang').innerText = 'No Language';
                    document.getElementById('lock-banner').classList.add('hidden');
                    updateSaveButtonState();
                    renderTree(document.getElementById('file-search').value);
                }
            } else {
                renderTabs();
            }
            
            // Save state after closing
            saveWorkspaceState();
        };

        function renderTabs() {
            const container = document.getElementById('tabs-bar');
            container.innerHTML = '';
            const files = Object.keys(openTabs);
            
            if (files.length === 0) {
                container.classList.add('hidden');
                return;
            }
            
            container.classList.remove('hidden');
            files.forEach(file => {
                const isActive = file === activeTab;
                const tabData = openTabs[file];
                const name = file.split('/').pop();
                
                const tab = document.createElement('div');
                tab.className = `group flex items-center px-3 py-1.5 border-t border-x rounded-t-md cursor-pointer text-xs font-medium transition-colors ${isActive ? 'bg-zinc-800 border-zinc-700 text-indigo-400 z-10' : 'bg-zinc-900 border-transparent text-zinc-500 hover:text-zinc-300 hover:bg-zinc-800/50'}`;
                tab.onclick = () => switchToTab(file);
                
                const dirtyIndicator = tabData.isDirty ? '<span class="w-1.5 h-1.5 rounded-full bg-amber-400 ml-1.5"></span>' : '';
                
                tab.innerHTML = `
                    <span class="truncate max-w-[120px]" title="${file}">${name}</span>
                    ${dirtyIndicator}
                    <button class="ml-2 opacity-0 group-hover:opacity-100 hover:text-red-400 transition-opacity ${isActive ? 'opacity-100' : ''}" onclick="window.closeTab('${file}', event)">
                        <i data-lucide="x" class="w-3 h-3"></i>
                    </button>
                `;
                container.appendChild(tab);
            });
            lucide.createIcons();
        }

        function updateSaveButtonState() {
            const saveBtn = document.getElementById('save-btn');
            const syncStatus = document.getElementById('status-sync');
            
            if (!activeTab) {
                saveBtn.className = 'flex items-center px-4 py-1.5 bg-indigo-600 text-white rounded-md text-sm font-medium transition-all shadow-sm opacity-50 cursor-not-allowed';
                saveBtn.innerHTML = `<i data-lucide="save" class="w-4 h-4 mr-1.5"></i> Save (Ctrl+S)`;
                syncStatus.innerText = 'Synced';
                syncStatus.classList.remove('text-amber-400');
                return;
            }

            const tabData = openTabs[activeTab];
            
            if (tabData.isReadOnly) {
                saveBtn.className = 'flex items-center px-4 py-1.5 bg-indigo-600 text-white rounded-md text-sm font-medium transition-all shadow-sm opacity-50 cursor-not-allowed';
                saveBtn.innerHTML = `<i data-lucide="save" class="w-4 h-4 mr-1.5"></i> Save (Ctrl+S)`;
                syncStatus.innerText = 'Locked';
                syncStatus.classList.remove('text-amber-400');
            } else if (tabData.isDirty) {
                saveBtn.className = 'flex items-center px-4 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium transition-all shadow-sm';
                saveBtn.innerHTML = `<i data-lucide="save" class="w-4 h-4 mr-1.5"></i> Save (Ctrl+S) *`;
                syncStatus.innerText = 'Unsaved Changes';
                syncStatus.classList.add('text-amber-400');
            } else {
                saveBtn.className = 'flex items-center px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md text-sm font-medium transition-all shadow-sm';
                saveBtn.innerHTML = `<i data-lucide="save" class="w-4 h-4 mr-1.5"></i> Save (Ctrl+S)`;
                syncStatus.innerText = 'Synced';
                syncStatus.classList.remove('text-amber-400');
            }
            lucide.createIcons();
        }

        function switchToTab(filename) {
            activeTab = filename;
            const tabData = openTabs[filename];
            
            // --- NEW: Show/Hide Preview Button based on file type ---
            const ext = filename.split('.').pop().toLowerCase();
            const previewBtn = document.getElementById('preview-btn');
            
            if (['md', 'html'].includes(ext)) {
                previewBtn.classList.remove('hidden');
            } else {
                previewBtn.classList.add('hidden');
                // Force close the preview panel if we switch to a file that doesn't support it
                if (window.isPreviewOpen) {
                    document.getElementById('editor-container').classList.remove('split-view');
                    const pane = document.getElementById('live-preview-pane');
                    if (pane) pane.remove();
                    window.isPreviewOpen = false;
                }
            }
            // --------------------------------------------------------

            Object.values(openTabs).forEach(tab => tab.dom.classList.add('hidden'));
            tabData.dom.classList.remove('hidden');
            
            document.getElementById('empty-state').classList.add('hidden');
            document.getElementById('status-lang').innerText = filename.split('.').pop().toUpperCase();
            
            currentFile = filename;
            isReadOnly = tabData.isReadOnly;
            editorView = tabData.view;
            
            const lockBanner = document.getElementById('lock-banner');
            
            if (isReadOnly) {
                lockBanner.classList.remove('hidden');
                if (tabData.lockedBy) {
                    document.getElementById('lock-text').innerText = `Locked by ${tabData.lockedBy}`;
                } else {
                    document.getElementById('lock-text').innerText = 'Read Only View';
                }
            } else {
                lockBanner.classList.add('hidden');
            }
            
            updateSaveButtonState();
            renderTabs();
            renderTree(document.getElementById('file-search').value); // Update active state in sidebar
        }

        window.loadFile = async function(filename) {
            if (openTabs[filename]) {
                switchToTab(filename);
                return;
            }

            // Image Preview Handler
            if (filename.match(/\.(jpg|jpeg|png|gif|svg)$/i)) {
                const editorContainer = document.createElement('div');
                editorContainer.className = 'absolute inset-0 hidden flex items-center justify-center bg-zinc-950 p-4';
                // Note: Ensure your PHP server actually serves files from the browser directly for this to work
                editorContainer.innerHTML = `<img src="?api=stream&file=${encodeURIComponent(filename)}" class="max-w-full max-h-full object-contain shadow-2xl border border-zinc-800 rounded">`;
                document.getElementById('editor-container').appendChild(editorContainer);
                
                openTabs[filename] = { dom: editorContainer, view: null, isReadOnly: true, lockedBy: null, isDirty: false };
                switchToTab(filename);
                saveWorkspaceState(); // Save to local history
                return;
            }

            const res = await api('load', { file: filename });
            if (res.status !== 'success') {
                showToast(res.message, 'error');
                return;
            }

            const editorContainer = document.createElement('div');
            editorContainer.className = 'absolute inset-0 hidden';
            document.getElementById('editor-container').appendChild(editorContainer);

            // Setup Keymaps: Save (Ctrl+S), Format (Shift+Alt+F), Emmet (Tab), Indent (Tab Fallback)
            const customKeymap = keymap.of([
                { key: "Mod-s", run: () => { saveCurrentFile(); return true; } },
                { key: "Shift-Alt-f", run: () => { window.formatCurrentFile(); return true; } },
                { key: "Tab", run: expandAbbreviation },
                indentWithTab
            ]);

            // Track Unsaved Changes
            const changeListener = EditorView.updateListener.of((update) => {
                if (update.docChanged && openTabs[filename]) {
                    if (!openTabs[filename].isDirty) {
                        openTabs[filename].isDirty = true;
                        renderTabs();
                        if (activeTab === filename) updateSaveButtonState();
                    }
                    // Add this line inside the change listener:
                    if (activeTab === filename) updatePreview();
                }
            });

            const view = new EditorView({
                state: EditorState.create({
                    doc: res.content,
                    extensions: [
                        basicSetup,
                        customKeymap,
                        changeListener,
                        EditorView.updateListener.of((update) => {
                            if (update.selectionSet && vimModeEnabled && activeTab === filename) {
                                setTimeout(() => {
                                    if (update.view) {
                                        update.view.dispatch({
                                            effects: relLineNumbersCompartment.reconfigure(getRelativeLineNumbers())
                                        });
                                    }
                                }, 0);
                            }
                        }),
                        autocompletion(),
                        closeBrackets(),
                        lintGutter(),
                        abbreviationTracker(),
                        themeCompartment.of(getThemeExt(document.getElementById('theme-select').value)),
                        langCompartment.of(getLanguageExt(filename)),
                        vimCompartment.of(vimModeEnabled ? vim() : []),
                        readOnlyCompartment.of(EditorState.readOnly.of(!res.canEdit)),
                        
                        // THIS MUST BE AT THE END (After basicSetup)
                        relLineNumbersCompartment.of(vimModeEnabled ? getRelativeLineNumbers() : [])
                    ]
                }),
                parent: editorContainer
            });

            openTabs[filename] = {
                dom: editorContainer,
                view: view,
                isReadOnly: !res.canEdit,
                lockedBy: res.lockedBy,
                isDirty: false
            };

            if (heartbeatInterval) clearInterval(heartbeatInterval);
            heartbeatInterval = setInterval(() => {
                if (!isReadOnly && currentFile) {
                    api('heartbeat', { file: currentFile });
                }
            }, 30000);
            
            switchToTab(filename);
        };

        // Workspace Persistence Helpers
        function saveWorkspaceState() {
            const tabsToSave = Object.keys(openTabs);
            localStorage.setItem('ide_open_tabs', JSON.stringify(tabsToSave));
            localStorage.setItem('ide_active_tab', activeTab);
        }

        function restoreWorkspaceState() {
            const savedTabs = JSON.parse(localStorage.getItem('ide_open_tabs') || '[]');
            const savedActive = localStorage.getItem('ide_active_tab');
            
            savedTabs.forEach(file => window.loadFile(file));
            if (savedActive && savedTabs.includes(savedActive)) {
                setTimeout(() => switchToTab(savedActive), 500); // Slight delay to ensure loads finish
            }
        }

        async function saveCurrentFile() {
            if (!currentFile || isReadOnly) return;
            const content = editorView.state.doc.toString();
            document.getElementById('status-sync').innerText = 'Saving...';
            
            const res = await api('save', { file: currentFile, content: content });
            
            if (res.status === 'success') {
                // Check Linter Results
                if (res.lintError) {
                    const lineText = res.lintLine ? ` on line ${res.lintLine}` : '';
                    showToast(`PHP Syntax Error${lineText}! Check console.`, 'error');
                    console.error("PHP Lint Error:", res.lintError);
                    document.getElementById('status-sync').innerText = `Syntax Error L${res.lintLine || '?'}`;
                    document.getElementById('status-sync').classList.add('text-rose-400');
                } else {                    showToast('File Saved Successfully');
                    document.getElementById('status-sync').innerText = 'Synced';
                    document.getElementById('status-sync').classList.remove('text-rose-400');
                }
                
                openTabs[currentFile].isDirty = false;
                renderTabs();
                updateSaveButtonState();
            } else {
                showToast(res.message, 'error');
                document.getElementById('status-sync').innerText = 'Sync Failed';
            }
        }

        // --- Auto Formatter (JS Beautify) ---
        window.formatCurrentFile = function() {
            if (!currentFile || isReadOnly || !editorView) return;
            
            const ext = currentFile.split('.').pop().toLowerCase();
            const content = editorView.state.doc.toString();
            let formatted = content;
            const options = { indent_size: 4, space_in_empty_paren: true };

            try {
                if (['js', 'json'].includes(ext)) {
                    formatted = window.js_beautify(content, options);
                } else if (['html', 'php'].includes(ext)) {
                    // HTML beautifier handles PHP surprisingly well by treating tags as text blocks
                    formatted = window.html_beautify(content, options);
                } else if (['css', 'scss'].includes(ext)) {
                    formatted = window.css_beautify(content, options);
                } else {
                    showToast("No auto-formatter available for this file type.", "error");
                    return;
                }

                if (formatted !== content) {
                    editorView.dispatch({
                        changes: { from: 0, to: editorView.state.doc.length, insert: formatted }
                    });
                    showToast("Code Formatted");
                } else {
                    showToast("Code is already formatted.");
                }
            } catch (err) {
                console.error("Format Error:", err);
                showToast("Formatting failed. Check console.", "error");
            }
        };

        // --- Global Event Listeners ---
        document.getElementById('refresh-files').addEventListener('click', refreshFileList);
        
        document.getElementById('save-btn').addEventListener('click', saveCurrentFile);
        document.getElementById('format-btn').addEventListener('click', window.formatCurrentFile);
        
        document.getElementById('theme-select').addEventListener('change', (e) => {
            const themeExt = getThemeExt(e.target.value);
            Object.values(openTabs).forEach(tab => {
                tab.view.dispatch({
                    effects: themeCompartment.reconfigure(themeExt)
                });
            });
        });

        document.getElementById('vim-toggle').addEventListener('click', (e) => {
            vimModeEnabled = !vimModeEnabled;
            const btn = e.currentTarget;
            const editorContainer = document.getElementById('editor-container');

            if (vimModeEnabled) {
                btn.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600');
                btn.classList.remove('bg-zinc-800', 'text-zinc-300');
                btn.innerHTML = `<i data-lucide="keyboard" class="w-3.5 h-3.5 mr-1.5"></i> Vim: On`;
                editorContainer.classList.add('vim-mode-active'); // Triggers CSS to hide default numbers
            } else {
                btn.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600');
                btn.classList.add('bg-zinc-800', 'text-zinc-300');
                btn.innerHTML = `<i data-lucide="keyboard" class="w-3.5 h-3.5 mr-1.5"></i> Vim: Off`;
                editorContainer.classList.remove('vim-mode-active');
            }
            lucide.createIcons();
            
            const vimExt = vimModeEnabled ? vim() : [];
            const relNumExt = vimModeEnabled ? getRelativeLineNumbers() : [];
            
            Object.values(openTabs).forEach(tab => {
                tab.view.dispatch({
                    effects: [
                        vimCompartment.reconfigure(vimExt),
                        relLineNumbersCompartment.reconfigure(relNumExt)
                    ]
                });
            });
            
            document.getElementById('status-mode').innerText = vimModeEnabled ? 'VIM MODE' : 'NORMAL';
        });

        document.getElementById('git-btn').addEventListener('click', async () => {
            if (!currentFile) {
                showToast("Please open a file to view git history", "error");
                return;
            }
            const panel = document.getElementById('bottom-panel');
            const logOut = document.getElementById('git-log-output');
            
            panel.classList.remove('hidden');
            logOut.innerText = 'Loading Git History...';
            
            const res = await api('git', { file: currentFile });
            if (res.status === 'success') {
                logOut.innerText = res.log;
            } else {
                logOut.innerText = res.message;
            }
        });

        document.getElementById('close-bottom-panel').addEventListener('click', () => {
            document.getElementById('bottom-panel').classList.add('hidden');
        });

        // Hard-Close Fallback: Unlock all files if the user closes the entire browser tab
        window.addEventListener('beforeunload', () => {
            Object.keys(openTabs).forEach(filename => {
                if (!openTabs[filename].isReadOnly) {
                    // Using fetch keepalive to ensure execution during browser unload
                    fetch(`?api=unlock&file=${encodeURIComponent(filename)}`, { method: 'GET', keepalive: true });
                }
            });
        });

        // Initialize App
        refreshFileList();

        // --- Context Menu Logic ---
        const contextMenu = document.getElementById('context-menu');
        let contextMenuTargetFile = null;

        document.getElementById('file-list').addEventListener('contextmenu', (e) => {
            // Find the closest file/folder row that was right-clicked
            const row = e.target.closest('[onclick^="window.loadFile"], [onclick^="window.toggleFolder"]');
            if (!row) return;

            e.preventDefault();
            
            // Extract the file path from the onclick attribute (hacky but effective for this setup)
            const onclickAttr = row.getAttribute('onclick');
            const match = onclickAttr.match(/'([^']+)'/);
            if (match) {
                contextMenuTargetFile = match[1];
                contextMenu.style.display = 'block';
                contextMenu.style.left = `${e.pageX}px`;
                contextMenu.style.top = `${e.pageY}px`;
            }
        });

        // Hide menu on outside click
        document.addEventListener('click', () => { contextMenu.style.display = 'none'; });

        // Bind Context Actions
        document.getElementById('ctx-rename').addEventListener('click', (e) => {
            if (contextMenuTargetFile) window.renameFile(contextMenuTargetFile, e);
        });
        restoreWorkspaceState();
    </script>
</body>
</html>