# Repo skeleton to create on first run

```
reporion/
├─ CLAUDE.md                      <- docs/reporion-CLAUDE.md
├─ LICENSE                        <- GPL-3.0 text
├─ README.md
├─ composer.json
├─ .gitignore                     <- dot-gitignore
├─ phpunit.xml
├─ public/
│  ├─ index.php                   <- public-index.php
│  └─ assets/                     (symlink or build target of ../assets)
├─ bin/
│  └─ reporion                    <- bin-reporion  (chmod +x)
├─ src/
│  ├─ Kernel.php
│  ├─ Http/         Request Response ApiResponse Router Session ErrorMapper
│  ├─ Controller/   Page Edit History Search Admin Export Public Auth Api\*
│  ├─ Service/      Pages Revisions Render Search Export Patients Index Ai Visibility
│  ├─ Storage/      StorageInterface FlatFile Journal RevisionStore MetaStore
│  ├─ Index/        IndexInterface Sqlite Migrator QueryBuilder
│  ├─ Schema/       SchemaLoader Validator FieldType
│  ├─ Import/       Scanner DokuWikiConverter MetaExtractor Committer
│  ├─ Plugin/       PluginInterface Hooks Loader
│  ├─ Cli/          Application + Command/*
│  ├─ Domain/       Page Revision Signature PatientKey Visibility
│  ├─ Exception/
│  └─ Support/      Slug Ulid Yaml Diff Hash Dates
├─ templates/
│  ├─ layout.php  layout-public.php
│  ├─ page/ edit/ history/ search/ admin/ errors/
│  └─ print/report.php            <- templates-print-report.php
├─ assets/
│  ├─ css/ app.css print.css      <- assets-css-print.css
│  └─ js/  palette.js editor.js search.js worklist.js admin.js
├─ conf/
│  ├─ local.php.example           <- conf.local.php.example
│  ├─ schema/ base.json ct.json mr.json us.json xr.json mg.json
│  ├─ synonyms.txt                <- conf-synonyms.txt
│  └─ import-map.json
├─ migrations/
│  └─ 001_init.sql                <- migrations-001_init.sql
├─ plugins/
│  ├─ export-pdf-letterhead/
│  └─ search-synonyms/
├─ design/                        <- design-README.md, design-tokens.css, mockup/
├─ docs/                          <- the three architecture md files + DECISIONS.md
│                                    + deploy-lighttpd.md + milestone-1.md
├─ tests/
│  ├─ Storage/ Index/ Visibility/ Render/ Import/
│  └─ fixtures/                   <- fixtures/*
└─ data/                          (gitignored; created by doctor)
   └─ pages/ media/ journal/ audit/ trash/ import/ cache/
```
