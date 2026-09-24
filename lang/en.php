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

    // login form
    'auth.username'       => 'Username',
    'auth.password'       => 'Password',
    'auth.invalid'        => 'Incorrect username or password.',
    'auth.tagline'        => 'A flat-file wiki for imaging reports — namespaced pages, signed revisions, full-text search across every modality and every site.',
    'auth.submit'         => 'Sign in',

    // public layout
    'public.label'        => 'public page',
    'nav.theme'           => 'Toggle theme',

    // home / landing page fallback (no site:home page created yet)
    'home.stub_body'      => "No `%s` page exists yet. Create one in the editor to set this installation's landing page.",

    // search
    'search.title'        => 'Search',
    'search.submit'       => 'Search',
    'search.noresults'    => 'No results for "%s".',
    'search.prompt'       => 'Enter a search term above.',
    'search.results_count' => '%d result(s) for "%s".',
    'search.match_count'  => '%d result(s) match',

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
    'page.delete_confirm_title'  => 'Delete this page?',
    'page.delete_confirm_body'   => 'This moves "%s" to trash. It stays recoverable there for %d days before a scheduled purge removes it for good.',
    'page.delete_confirm_submit' => 'Delete',
    'page.compare'        => 'Compare with prior',
    'page.timeline'       => 'Patient timeline',
    'page.print'          => 'Print / export preview',
    'page.toc'            => 'On this page',
    'page.back'           => 'Back to page',

    // history
    'history.rev_count'   => '%d revision(s)',
    'history.col_rev'     => 'rev',
    'history.col_when'    => 'when',
    'history.col_author'  => 'author',
    'history.col_change'  => 'change',
    'history.col_note'    => 'note',
    'history.col_size'    => 'size',
    'history.bytes'       => '%d B',
    'history.current'     => 'current',
    'history.diff'        => 'diff',
    'history.restore'     => 'restore',
    'history.diff_title'  => 'Diff rev %d → rev %d',

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
    'editor.editing'      => 'editing',
    'editor.rev'          => 'rev %d',
    'editor.conflict_current' => 'Current version on the server',
    'editor.err_conflict' => 'Someone else saved a newer revision. Your text is unchanged below — reconcile with the current version shown, then save again.',
    'editor.err_parse'    => 'Could not parse the document: %s',
    'editor.err_malformed' => 'The document must start with a "---" frontmatter block.',
    'editor.err_frontmatter_not_map' => 'The frontmatter block must be a YAML mapping, not a list or scalar.',

    // new page
    'new.title'           => 'New page',
    'new.path'            => 'Path',
    'new.create'          => 'Create',
    'new.err_path_required' => 'A path is required.',
    'new.err_invalid_path' => 'That path is not valid — no empty segments, no "/", no leading or trailing ":".',

    // namespace index
    'ns.badge'             => 'namespace',
    'ns.new_page'          => 'New page',
    'ns.page_count'        => '%d page(s)',
    'ns.direct_page_count' => '%d page(s) directly here',
    'ns.subnamespace'      => 'subnamespace',
    'ns.pages_here'        => 'Pages in this namespace',
    'ns.col_page'          => 'page',
    'ns.col_title'         => 'title',
    'ns.col_status'        => 'status',
    'ns.col_visibility'    => 'visibility',
    'ns.col_updated'       => 'updated',

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

    // admin: users
    'admin.users.title'         => 'Users',
    'admin.users.col_user'      => 'user',
    'admin.users.col_grants'    => 'grants',
    'admin.users.col_status'    => 'status',
    'admin.users.owner'         => 'owner',
    'admin.users.active'        => 'active',
    'admin.users.inactive'      => 'inactive',
    'admin.users.deactivate'    => 'Deactivate',
    'admin.users.reactivate'    => 'Reactivate',
    'admin.users.create_title'  => 'Create account',
    'admin.users.make_owner'    => 'Owner (instance-wide access)',
    'admin.users.grants_label'  => 'Namespace grants — one per line, e.g. reports:mri:editor',
    'admin.users.create_submit' => 'Create account',
    'admin.users.err_required'  => 'Username and password are required.',
    'admin.users.err_last_owner' => 'Cannot deactivate the last active owner account.',

    // publishing
    'publish.confirm.title' => 'Make this report public?',
    'publish.confirm.body'  => 'These will become visible to anyone:',
    'publish.confirm.patient' => 'patient identifiers in metadata',
    'publish.confirm.media'   => '%d attached image(s)',
    'publish.confirm.links'   => '%d inbound link(s) from private pages',
    'publish.confirm.suggest' => 'Safer: duplicate, pseudonymise, publish the copy.',
];
