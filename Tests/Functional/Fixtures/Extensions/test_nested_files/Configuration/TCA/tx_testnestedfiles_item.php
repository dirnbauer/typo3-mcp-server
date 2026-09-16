<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Collection item',
        'label' => 'title',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Marks the table as an embedded child: it is only ever written through its parent.
        'hideTable' => true,
        'versioningWS' => true,
        'security' => [
            // Inline children are written wherever their parent lives.
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 50,
            ],
        ],
        // The field this fixture exists for: a file on the child, not on the parent.
        'file' => [
            'label' => 'File',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
            ],
        ],
        'tt_content_items' => [
            'label' => 'Parent',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'parent_item' => [
            'config' => ['type' => 'passthrough'],
        ],
        'children' => [
            'label' => 'Nested items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testnestedfiles_item',
                'foreign_field' => 'parent_item',
                'foreign_sortby' => 'sorting',
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'title, file, children'],
    ],
];
