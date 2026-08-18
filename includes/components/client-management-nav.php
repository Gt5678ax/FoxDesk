<?php
/**
 * Shared navigation for company records and their contact accounts.
 */

$client_management_section = $_GET['section'] ?? 'organizations';
?>
<nav class="client-management-nav" aria-label="<?php echo e(t('Clients')); ?>">
    <a href="<?php echo e(url('admin', ['section' => 'organizations'])); ?>"
        class="client-management-nav__item <?php echo $client_management_section === 'organizations' ? 'is-active' : ''; ?>"
        <?php echo $client_management_section === 'organizations' ? 'aria-current="page"' : ''; ?>>
        <?php echo get_icon('building', 'w-4 h-4'); ?>
        <span><?php echo e(t('Companies')); ?></span>
    </a>
    <a href="<?php echo e(url('admin', ['section' => 'clients'])); ?>"
        class="client-management-nav__item <?php echo $client_management_section === 'clients' ? 'is-active' : ''; ?>"
        <?php echo $client_management_section === 'clients' ? 'aria-current="page"' : ''; ?>>
        <?php echo get_icon('users', 'w-4 h-4'); ?>
        <span><?php echo e(t('Contacts')); ?></span>
    </a>
</nav>
