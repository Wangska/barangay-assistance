<?php // Expects $notification; timestamps use the application's configured timezone. ?>
<div class="small text-muted mb-2">
    <?php if (!empty($notification['announced_at'])): ?>
    <div>Announced:
        <time datetime="<?= e(date('c', strtotime($notification['announced_at']))) ?>"><?= e(date('M j, Y \a\t g:i A', strtotime($notification['announced_at']))) ?></time>
    </div>
    <?php endif; ?>
    <div>Notified:
        <?php if (!empty($notification['notified_at'])): ?>
        <time datetime="<?= e(date('c', strtotime($notification['notified_at']))) ?>"><?= e(date('M j, Y \a\t g:i A', strtotime($notification['notified_at']))) ?></time>
        <?php else: ?>
        Not recorded for this older notification
        <?php endif; ?>
    </div>
</div>