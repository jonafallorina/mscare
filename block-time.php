<?php

header('Location: settings.php?s=blocktime' . (isset($_GET['saved']) ? '&saved=' . (int)$_GET['saved'] . '&n=' . (int)($_GET['n'] ?? 0) : ''));
exit;
