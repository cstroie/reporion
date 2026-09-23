<?php
declare(strict_types=1);

// lang/en.php — every user-facing string (D26). Templates call t('key').
// Adding ro.php later is a translation job, not a refactor.
// Report CONTENT is never translated; these are interface strings only.

return [
    // chrome
    'app.name'            => 'Reporion',
    'nav.search'          => 'Search reports, protocols, findings…',
    'nav.new'             => 'New report',
    'nav.admin'           => 'Admin',
    'nav.account'         => 'Account',
    'nav.signin'          => 'Sign in',
    'nav.signout'         => 'Sign out',
    'nav.theme'           => 'Toggle theme',

    // page actions
    'page.edit'           => 'Edit',
    'page.history'        => 'History',
    'page.export'         => 'Export',
    'page.rename'         => 'Rename page',
    'page.move'           => 'Move / change namespace',
    'page.duplicate'      => 'Duplicate as new report',
    'page.duplicate.pseudo' => 'Duplicate, pseudonymised (for teaching)',
    'page.template'       => 'Save as template',
    'page.visibility'     => 'Visibility',
    'page.sign'           => 'Sign & lock revision',
    'page.revert'         => 'Revert to last signed',
    'page.delete'         => 'Delete (soft, %d d)',
    'page.compare'        => 'Compare with prior',
    'page.timeline'       => 'Patient timeline',
    'page.print'          => 'Print / export preview',
    'page.toc'            => 'On this page',

    // metadata labels
    'meta.title'          => 'Metadata',
    'meta.patient'        => 'Patient',
    'meta.cnp'            => 'CNP (optional)',
    'meta.accession'      => 'Accession',
    'meta.study_date'     => 'Study date',
    'meta.modality'       => 'Modality',
    'meta.region'         => 'Region',
    'meta.device'         => 'Device',
    'meta.site'           => 'Site',
    'meta.referrer'       => 'Referrer',
    'meta.protocol'       => 'Protocol',
    'meta.template'       => 'Template',
    'meta.tags'           => 'Tags',
    'meta.priors'         => 'Priors',
    'meta.summary'        => 'Summary',
    'meta.visibility'     => 'Visibility',

    // status & visibility
    'status.draft'        => 'draft',
    'status.signed'       => 'signed',
    'status.archived'     => 'archived',
    'vis.private'         => 'private',
    'vis.unlisted'        => 'unlisted',
    'vis.public'          => 'public',

    // editor
    'editor.save'         => 'Save rev %d',
    'editor.save_sign'    => 'Save & sign',
    'editor.preview'      => 'Preview',
    'editor.cancel'       => 'Cancel',
    'editor.note'         => 'What changed?',
    'editor.minor'        => 'minor edit',
    'editor.autosaved'    => 'autosaved %s ago',
    'editor.offline'      => 'Unsaved — reconnecting…',
    'editor.template'     => 'Insert template',

    // errors
    'err.404.title'       => 'Page not found',
    'err.404.body'        => 'Nothing at this path.',
    'err.404.create'      => 'Create this page',
    'err.410.title'       => 'Page deleted',
    'err.410.body'        => 'In the trash and restorable until purge.',
    'err.401.title'       => 'Session expired',
    'err.401.body'        => 'Your unsaved draft is kept in this browser.',
    'err.409.title'       => 'Edit conflict',
    'err.409.body'        => 'This page changed while you were editing.',
    'err.409.merge'       => 'Merge both',
    'err.empty.title'     => 'Empty namespace',
    'err.500.title'       => 'Something broke',

    // publishing
    'publish.confirm.title' => 'Make this report public?',
    'publish.confirm.body'  => 'These will become visible to anyone:',
    'publish.confirm.patient' => 'patient identifiers in metadata',
    'publish.confirm.media'   => '%d attached image(s)',
    'publish.confirm.links'   => '%d inbound link(s) from private pages',
    'publish.confirm.suggest' => 'Safer: duplicate, pseudonymise, publish the copy.',
];
