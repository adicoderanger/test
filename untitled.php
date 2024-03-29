<?php
/**
 * @file
 * Install, update and uninstall functions for the profilename install profile.
 */

use Drupal\block\Entity\Block;
use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\Yaml\Yaml;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\Entity\Role;
use Drupal\contact\Entity\ContactForm;
use Drupal\Core\Field\Entity\BaseFieldOverride;

/**
 * Implements hook_install().
 *
 * Perform actions to set up the site for this profile.
 *
 * @see system_install()
 */
function ncar_ucar_umbrella_install()
{
  // First, do everything in standard profile.
  include_once DRUPAL_ROOT . '/core/profiles/standard/standard.install';
  standard_install();

  // Can add code in here to make nodes, terms, etc.
  $vocabularies = [
    'topics' => [
      'name' => 'Topics',
      'description' => 'The topics associated with each news story',
      'terms' => [
        'Air Quality' => [],
        'Climate' => [],
        'Data' => [],
        'Education & Outreach' => [],
        'Government Relations' => [],
        'Organization' => [],
        'Sun & Space Weather' => [],
        'Supercomputing' => [],
        'Water' => [],
        'Weather' => []
      ]
    ],
    'tags' => [
      'name' => 'Tags',
      'description' => 'The tags associated with each news story',
      'terms' => [
        'Chemistry' => [],
        'Diversity' => [],
        'Ecosystems' => [],
        'Energy' => [],
        'Events' => [],
        'Health' => [],
        'Ice' => [],
        'Land' => [],
        'Modeling' => [],
        'Observing' => [],
        'Ocean' => [],
        'People' => [],
        'Societal Impacts' => [],
        'Tech Transfer' => [],
        'Transportation' => [],
        'Wildfire' => [],
        'News Release' => [],
        'Staff News' => [],
        'Washington Update' => []
      ]
    ],
    'organizations' => [
      'name' => 'Organizations',
      'description' => 'The organization associated with each news story',
      'terms' => [
        'NCAR' => [
          'ACOM',
          'CGD',
          'CISL',
          'EOL',
          'HAO',
          'MMM',
          'RAL'
        ],
        'UCAR' => [],
        'UCAR Community Programs' => [
          'COMET',
          'COSMIC',
          'CPAESS',
          'GLOBE',
          'SCIED',
          'UNIDATA'
        ]
      ]
    ]
  ];

  foreach ($vocabularies as $vocabulary => $values) {
    $existingVocabulary = Vocabulary::load($vocabulary);

    if (!$existingVocabulary) {
      $newVocabulary = Vocabulary::create([
        'name' => $values['name'],
        'description' => $values['description'],
        'vid' => $vocabulary,
      ]);
      $newVocabulary->save();
    }

    foreach ($values['terms'] as $key => $value) {
      $existingTerm = Term::load($key);

      if (!$existingTerm) {
        $parentTerm = Term::create([
          'name' => $key,
          'vid' => $vocabulary,
        ]);

        $parentTerm->save();

        if (is_array($value)) {
          foreach ($value as $child) {
            $newChild = Term::create([
              'name' => $child,
              'vid' => $vocabulary,
              'parent' => $parentTerm->id()
            ]);

            $newChild->save();
          }
        }
      }
    }
  }
}

/**
 * Switch the video paragraph to use youtube module rather than video module
 */
function ncar_ucar_umbrella_update_8001()
{

  $profile_config_dir = drupal_get_path('profile', 'ncar_ucar_umbrella') . '/config/install';

  \Drupal::service('module_installer')->install(['youtube']);

  $config_path = $profile_config_dir . '/youtube.settings.yml';
  $data = Yaml::parse($config_path);
  \Drupal::configFactory()
    ->getEditable('youtube.settings')
    ->setData($data)
    ->save(TRUE);

  $db = \Drupal::database();

  //get field data to re-insert later
  $links = $db
    ->select('paragraph__field_video_link')
    ->fields('paragraph__field_video_link')
    ->execute()
    ->fetchAll();

  //get revision data
  $revisions = $db
    ->select('paragraph_revision__field_video_link')
    ->fields('paragraph_revision__field_video_link')
    ->execute()
    ->fetchAll();

  //delete video_link field using video module
  /** @var \Drupal\Core\Entity\EntityFieldManager $entityManager */
  $entityManager = \Drupal::service('entity_field.manager');
  $fields = $entityManager->getFieldDefinitions('paragraph', 'video');

  /** @var Drupal\field\Entity\FieldConfig $field */
  if ($field = $fields['field_video_link']) {
    $field->delete();
    field_purge_batch(200);
  }

  //add video_link field using youtube module
  if (!FieldConfig::load('field_video_link')) {

    $config = \Drupal::configFactory();

    //update entity config
    $config_path = $profile_config_dir . '/core.entity_form_display.paragraph.video.default.yml';
    $data = Yaml::parse($config_path);
    $config->getEditable('core.entity_form_display.paragraph.video.default')
      ->setData($data)
      ->save(TRUE);

    $config_path = $profile_config_dir . '/core.entity_view_display.paragraph.video.default.yml';
    $data = Yaml::parse($config_path);
    $config->getEditable('core.entity_view_display.paragraph.video.default')
      ->setData($data)
      ->save(TRUE);

    //these actually create the paragraph__field_video_link and revision tables
    $field_storage = entity_create('field_storage_config', array(
      'field_name' => 'field_video_link',
      'entity_type' => 'paragraph',
      'type' => 'youtube',
    ));
    $field_storage->save();
    $field = entity_create('field_config', array(
      'entity_type' => 'paragraph',
      'field_name' => 'field_video_link',
      'field_storage' => $field_storage,
      'bundle' => 'video',
      'label' => "Video Link",
    ));
    $field->save();

    //reset field config
    $config_path = $profile_config_dir . '/field.storage.paragraph.field_video_link.yml';
    $data = Yaml::parse($config_path);
    $config->getEditable('field.storage.paragraph.field_video_link')
      ->setData($data)
      ->save(TRUE);

    $config_path = $profile_config_dir . '/field.field.paragraph.video.field_video_link.yml';
    $data = Yaml::parse($config_path);
    $config->getEditable('field.field.paragraph.video.field_video_link')
      ->setData($data)
      ->save(TRUE);
  }

  //add link data back to table
  $fields = ['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', 'field_video_link_input', 'field_video_link_video_id'];
  foreach ($links as $link) {
    $query = $db->insert('paragraph__field_video_link');
    $query->fields($fields);
    $values = [];
    foreach ($fields as $field) {
      if (isset($link->$field)) {
        $values[] = $link->$field;
      }
    }
    //video module stores data as serialized string
    $data = unserialize($link->field_video_link_data);
    $values[] = $data[0];//full yt url
    $values[] = $data['id'];//just the yt video id
    $query->values($values);
    $query->execute();
  }

  //add revision data
  foreach ($revisions as $revision) {
    $query = $db->insert('paragraph_revision__field_video_link');
    $query->fields($fields);
    $values = [];
    foreach ($fields as $field) {
      if (isset($revision->$field)) {
        $values[] = $revision->$field;
      }
    }
    //video module stores data as serialized string
    $data = unserialize($revision->field_video_link_data);
    $values[] = $data[0];//full yt url
    $values[] = $data['id'];//just the yt video id
    $query->values($values);
    $query->execute();
  }

  //remove video module
  \Drupal::service('module_installer')->uninstall(['video']);
}

/**
 * Add subtitle field to thumbnail portrait paragraph type
 */
function ncar_ucar_umbrella_update_8101()
{
  $fieldStorage = FieldStorageConfig::loadByName('paragraph', 'field_tadp_item_subtitle');
  $fieldSubtitle = FieldConfig::loadByName('paragraph', 'tadp_item', 'field_tadp_item_subtitle');
  $fieldDescription = FieldConfig::loadByName('paragraph', 'tadp_item', 'field_tadp_item_description');
  $fieldLink = FieldConfig::loadByName('paragraph', 'tadp_item', 'field_tadp_item_link');
  $fieldImage = FieldConfig::loadByName('paragraph', 'tadp_item', 'field_tadp_item_image');

  if (empty($fieldStorage)) {
    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_tadp_item_subtitle',
      'entity_type' => 'paragraph',
      'type' => 'string'
    ]);

    $fieldStorage->save();
  }

  if (empty($fieldSubtitle)) {
    $fieldSubtitle = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => 'tadp_item',
      'label' => 'Subtitle',
      'field_name' => 'field_tadp_item_subtitle',
      'entity_type' => 'paragraph'
    ]);

    $fieldSubtitle->save();

    $formDisplay = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('paragraph' . '.' . 'tadp_item' . '.' . 'default');

    $formDisplay->setComponent('field_tadp_item_subtitle', array(
      'type' => 'string_textfield',
      'weight' => 1,
    ))
      ->save();

    $viewDisplay = \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load('paragraph' . '.' . 'tadp_item' . '.' . 'default');

    $viewDisplay->setComponent('field_tadp_item_subtitle', array(
      'label' => 'hidden',
      'type' => 'text_default',
      'weight' => 1,
    ))
      ->save();

    if ($fieldDescription) {
      $formDisplay->setComponent('field_tadp_item_description', array(
        'weight' => 2,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadp_item_description', array(
        'label' => 'hidden',
        'weight' => 2,
      ))
        ->save();
    }

    if ($fieldLink) {
      $formDisplay->setComponent('field_tadp_item_link', array(
        'weight' => 3,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadp_item_link', array(
        'label' => 'hidden',
        'weight' => 3,
      ))
        ->save();
    }

    if ($fieldImage) {
      $formDisplay->setComponent('field_tadp_item_image', array(
        'weight' => 4,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadp_item_image', array(
        'label' => 'hidden',
        'weight' => 4,
      ))
        ->save();
    }
  }
}

/**
 * Make URL field optional on thumbnail portrait paragraph type
 */
function ncar_ucar_umbrella_update_8102()
{
  $fieldLink = FieldConfig::loadByName('paragraph', 'tadp_item', 'field_tadp_item_link');
  if ($fieldLink instanceof FieldConfig && $fieldLink->isRequired()) {
    $fieldLink->setRequired(false)->save();
  }
}

/**
 * Add Internal Links Paragraph type to Related Links
 */
function ncar_ucar_umbrella_update_8103()
{
  $entity_type = 'paragraph';
  $paragraph_type_name = 'internal_link';
  $field_name = 'field_' . $paragraph_type_name . '_link';

  $paragraph_type = ParagraphsType::create([
    'label' => 'Internal Link',
    'id' => $paragraph_type_name,
    'behavior_plugins' => [],
  ]);
  $paragraph_type->save();

  $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
  if (!$field_storage) {
    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'link'
    ]);
    $field_storage->save();
  }

  $field_config = FieldConfig::loadByName($entity_type, $paragraph_type_name, $field_name);
  if (!$field_config) {
    $field_config = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $paragraph_type_name,
      'label' => 'Internal Link',
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'settings' => [
        'link_type' => 1, //internal only
        'title' => 2 //require link text
      ]
    ]);
    $field_config->save();
  }

  $manager = \Drupal::entityTypeManager();

  $formDisplay = $manager->getStorage('entity_form_display')
    ->create([
      'targetEntityType' => $entity_type,
      'bundle' => $paragraph_type_name,
      'mode' => 'default',
      'status' => true,
    ]);
  $formDisplay->setComponent($field_name, array(
    'type' => 'link_default'
  ))->save();

  $viewDisplay = $manager->getStorage('entity_view_display')
    ->create([
      'targetEntityType' => $entity_type,
      'bundle' => $paragraph_type_name,
      'mode' => 'default',
      'status' => TRUE,
    ]);

  $viewDisplay->setComponent($field_name, array(
    'label' => 'hidden',
    'type' => 'link'
  ))->save();

  $field = FieldConfig::loadByName('node', 'page', 'field_page_related_links');
  if ($field instanceof FieldConfig) {
    $settings = $field->getSettings();
    $target_bundles = $settings['handler_settings']['target_bundles'];
    $target_bundles += ['internal_link' => 'internal_link'];

    $target_bundles_drag_drop = $settings['handler_settings']['target_bundles_drag_drop'];
    $target_bundles_drag_drop += ['internal_link' => ['enabled' => 1]];

    $field->setSetting('handler_settings', [
      'target_bundles' => $target_bundles,
      'target_bundles_drag_drop' => $target_bundles_drag_drop
    ])->save();
  }
}

/**
 * Add Details Table Item and Details Table components
 */
function ncar_ucar_umbrella_update_8104()
{
  $entity_type = 'paragraph';
  $paragraphs = [
    'details_table_item' => [
      'label' => 'Details Table Item',
      'fields' => [
        'title' => [
          'type' => 'string',
          'label' => 'Title',
          'form_display' => [
            'type' => 'string_textfield',
            'weight' => 0
          ],
          'view_display' => [
            'type' => 'string',
            'label' => 'hidden'
          ]
        ],
        'descrip' => [
          'type' => 'text_long',
          'label' => 'Description',
          'default_value' => [
            [
              'value' => ' ', // account for a bug in Drupal that does not set the default format without a set value
              'format' => 'details_table'
            ]
          ],
          'form_display' => [
            'type' => 'text_textarea',
            'weight' => 1
          ],
          'view_display' => [
            'type' => 'text_default',
            'label' => 'hidden'
          ]
        ],
        'details' => [
          'type' => 'text_long',
          'label' => 'Details',
          'default_value' => [
            [
              'value' => ' ', // account for a bug in Drupal that does not set the default format without a set value
              'format' => 'details_table'
            ]
          ],
          'form_display' => [
            'type' => 'text_textarea',
            'weight' => 2
          ],
          'view_display' => [
            'type' => 'text_default',
            'label' => 'hidden'
          ]
        ]
      ],
    ],
    'details_table' => [
      'label' => 'Details Table',
      'fields' => [
        'title' => [
          'type' => 'string',
          'label' => 'Title',
          'form_display' => [
            'type' => 'string_textfield',
            'weight' => 0
          ],
          'view_display' => [
            'type' => 'string',
            'label' => 'hidden'
          ]
        ],
        'description' => [
          'type' => 'string_long',
          'label' => 'Description',
          'required' => false,
          'form_display' => [
            'type' => 'string_textarea',
            'weight' => 1
          ],
          'view_display' => [
            'type' => 'basic_string',
            'label' => 'hidden'
          ]
        ],
        'width' => [
          'type' => 'list_string',
          'label' => 'Component Width',
          'storage_settings' => [
            'allowed_values' => [
              'narrow' => 'Narrow',
              'wide' => 'Wide'
            ]
          ],
          'form_display' => [
            'type' => 'options_select',
            'weight' => 2
          ],
          'view_display' => [
            'type' => 'list_default',
            'label' => 'hidden'
          ]
        ],
        'item' => [
          'type' => 'entity_reference_revisions',
          'label' => 'Details Table Item',
          'cardinality' => -1,
          'settings' => [
            'handler_settings' => [
              'target_bundles' => [
                'details_table_item' => 'details_table_item'
              ],
              'target_bundles_drag_drop' => [
                'details_table_item' => [
                  'enabled' => true,
                ]
              ]
            ]
          ],
          'storage_settings' => [
            'target_type' => 'paragraph'
          ],
          'form_display' => [
            'type' => 'entity_reference_paragraphs',
            'weight' => 3,
          ],
          'view_display' => [
            'type' => 'entity_reference_revisions_entity_view',
            'label' => 'hidden'
          ]
        ]
      ]
    ]
  ];

  $filtered_html_format = FilterFormat::create([
    'format' => 'details_table',
    'name' => 'Details Table',
    'weight' => 5,
    'filters' => [
      'filter_html' => [
        'status' => true,
        'settings' => [
          'allowed_html' => '<br> <strong> <ul type> <ol start type> <li> <dl> <dt> <dd>',
        ],
      ],
    ],
  ]);
  $filtered_html_format->save();

  $editor = Editor::create([
    'format' => 'details_table',
    'editor' => 'ckeditor',
    'settings' => [
      'toolbar' => [
        'rows' => [
          [
            [
              'name' => 'Formatting',
              'items' => [
                'Bold',
              ],
            ],
            [
              'name' => 'Lists',
              'items' => [
                'BulletedList',
                'NumberedList',
                'Outdent',
                'Indent',
              ],
            ],
            [
              'name' => 'Tools',
              'items' => [
                'Source',
              ],
            ],
          ],
        ],
      ],
    ]
  ]);
  $editor->save();

  $manager = \Drupal::entityTypeManager();

  foreach ($paragraphs as $paragraph => $settings) {
    $paragraphEntity = $manager->getStorage('paragraphs_type')
      ->load($paragraph);

    if (empty($paragraphEntity)) {
      $paragraph_type = ParagraphsType::create([
        'label' => $settings['label'],
        'id' => $paragraph,
        'behavior_plugins' => [],
      ]);
      $paragraph_type->save();
    }

    $formDisplay = $manager->getStorage('entity_form_display')
      ->create([
        'targetEntityType' => $entity_type,
        'bundle' => $paragraph,
        'mode' => 'default',
        'status' => true,
      ]);

    $viewDisplay = $manager->getStorage('entity_view_display')
      ->create([
        'targetEntityType' => $entity_type,
        'bundle' => $paragraph,
        'mode' => 'default',
        'status' => TRUE,
      ]);

    foreach ($settings['fields'] as $field => $options) {
      $field_name = 'field_' . $paragraph . '_' . $field;
      $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);

      if (!$field_storage) {
        $field_storage = FieldStorageConfig::create([
          'field_name' => $field_name,
          'entity_type' => $entity_type,
          'type' => $options['type'],
          'cardinality' => isset($options['cardinality']) ? $options['cardinality'] : 1,
          'settings' => isset($options['storage_settings']) ? $options['storage_settings'] : array(),
        ]);
        $field_storage->save();
      }

      $field_config = FieldConfig::loadByName($entity_type, $paragraph, $field_name);

      if (!$field_config) {
        $field_config = FieldConfig::create([
          'field_storage' => $field_storage,
          'bundle' => $paragraph,
          'label' => $options['label'],
          'field_name' => $field_name,
          'entity_type' => $entity_type,
          'required' => isset($options['required']) ? $options['required'] : true,
          'settings' => isset($options['settings']) ? $options['settings'] : array(),
          'default_value' => isset($options['default_value']) ? $options['default_value'] : array()
        ]);
        $field_config->save();
      }

      $formDisplay->setComponent($field_name, $options['form_display']);
      $viewDisplay->setComponent($field_name, $options['view_display']);
    }

    $formDisplay->save();
    $viewDisplay->save();
  }

  $field = FieldConfig::loadByName('node', 'page', 'field_page_components');

  if ($field instanceof FieldConfig) {
    $settings = $field->getSettings();
    $target_bundles = $settings['handler_settings']['target_bundles'];
    $target_bundles += ['details_table' => 'details_table'];

    $target_bundles_drag_drop = $settings['handler_settings']['target_bundles_drag_drop'];
    $target_bundles_drag_drop += ['details_table' => ['enabled' => 1, 'weight' => 1]];
    $target_bundles_drag_drop['big_picture_box'] = ['enabled' => 1, 'weight' => 0];

    $field->setSetting('handler_settings', [
      'target_bundles' => $target_bundles,
      'target_bundles_drag_drop' => $target_bundles_drag_drop
    ])->save();
  }
}

/**
 * Update 4 blocks to be visible only on page content type
 */
function ncar_ucar_umbrella_update_8105() {

  $default_theme = \Drupal::config('system.theme')->get('default');

  $update_blocks =  ['bannerblock', 'componentsblock', 'relatedlinksblock', 'sidebarblock'];

  $visibility_config = [
    'node_type' => [
      'bundles' => ['page' => 'page'],
      'negate' => false,
      'context_mapping' => ['node' => '@node.node_route_context:node']
    ],
    'entity_bundle:node' => [
      'bundles' => ['page' => 'page'],
      'negate' => false,
      'context_mapping' => ['node' => '@node.node_route_context:node']
    ]
  ];

  foreach($update_blocks as $block_name) {
    $koru_block = Block::load($block_name);
    $theme_block = Block::load($default_theme . '_' . $block_name);

    foreach($visibility_config as $id => $config) {
      if($koru_block) {
        $koru_block->setVisibilityConfig($id, $config);
        $koru_block->save();
      }
      if($theme_block) {
        $theme_block->setVisibilityConfig($id, $config);
        $theme_block->save();
      }
    }
  }
}

/**
 * Make description field optional on thumbnail and video paragraph types
 */
function ncar_ucar_umbrella_update_8106()
{
  $paragraphs = [
    'tadl_list' => [
      'entity' => 'paragraph',
      'field' => 'field_tadl_list_description'
    ],
    'tadp_list' => [
      'entity' => 'paragraph',
      'field' => 'field_tadp_list_description'
    ],
    'video' => [
      'entity' => 'paragraph',
      'field' => 'field_video_description'
    ]
  ];

  foreach ($paragraphs as $paragraph => $field) {
    $fieldDescription = FieldConfig::loadByName($field['entity'], $paragraph, $field['field']);

    if ($fieldDescription instanceof FieldConfig && $fieldDescription->isRequired()) {
      $fieldDescription->setRequired(false)->save();
    }
  }
}

/**
 * Make URL field optional on thumbnail landscape paragraph type
 */
function ncar_ucar_umbrella_update_8107()
{
  $fieldLink = FieldConfig::loadByName('paragraph', 'tadl_item', 'field_tadl_item_link');
  if ($fieldLink instanceof FieldConfig && $fieldLink->isRequired()) {
    $fieldLink->setRequired(false)->save();
  }
}

/**
 * Update Details Table filter to allow <p> and <a> tags. Add Link and Unlink buttons to the editor
 */
function ncar_ucar_umbrella_update_8108()
{
  $detailsTable = FilterFormat::load('details_table');
  $detailsTable->filters('filter_html')->settings['allowed_html'] .= ' <p> <a href>';
  $detailsTable->save();

  $detailsEditor = Editor::load('details_table');
  $settings = $detailsEditor->getSettings();
  $settings['toolbar']['rows'][0][0]['items'][] = 'DrupalLink';
  $settings['toolbar']['rows'][0][0]['items'][] = 'DrupalUnlink';
  $detailsEditor->setSettings($settings);
  $detailsEditor->save();
}

/**
 * Add Topics, Tags and Organizations vocabularies and terms
 */
function ncar_ucar_umbrella_update_8109()
{
  $vocabularies = [
    'topics' => [
      'name' => 'Topics',
      'description' => 'The topics associated with each news story',
      'terms' => [
        'Air Quality' => [],
        'Climate' => [],
        'Data' => [],
        'Education & Outreach' => [],
        'Government Relations' => [],
        'Organization' => [],
        'Sun & Space Weather' => [],
        'Supercomputing' => [],
        'Water' => [],
        'Weather' => []
      ]
    ],
    'tags' => [
      'name' => 'Tags',
      'description' => 'The tags associated with each news story',
      'terms' => [
        'Chemistry' => [],
        'Diversity' => [],
        'Ecosystems' => [],
        'Energy' => [],
        'Events' => [],
        'Health' => [],
        'Ice' => [],
        'Land' => [],
        'Modeling' => [],
        'Observing' => [],
        'Ocean' => [],
        'People' => [],
        'Societal Impacts' => [],
        'Tech Transfer' => [],
        'Transportation' => [],
        'Wildfire' => [],
        'News Release' => [],
        'Staff News' => [],
        'Washington Update' => []
      ]
    ],
    'organizations' => [
      'name' => 'Organizations',
      'description' => 'The organization associated with each news story',
      'terms' => [
        'NCAR' => [
          'ACOM',
          'CGD',
          'CISL',
          'EOL',
          'HAO',
          'MMM',
          'RAL'
        ],
        'UCAR' => [],
        'UCAR Community Programs' => [
          'COMET',
          'COSMIC',
          'CPAESS',
          'GLOBE',
          'SCIED',
          'UNIDATA'
        ]
      ]
    ]
  ];

  foreach ($vocabularies as $vocabulary => $values) {
    $existingVocabulary = Vocabulary::load($vocabulary);

    if (!$existingVocabulary) {
      $newVocabulary = Vocabulary::create([
        'name' => $values['name'],
        'description' => $values['description'],
        'vid' => $vocabulary,
      ]);
      $newVocabulary->save();
    }

    foreach ($values['terms'] as $key => $value) {
      $existingTerm = Term::load($key);

      if (!$existingTerm) {
        $parentTerm = Term::create([
          'name' => $key,
          'vid' => $vocabulary,
        ]);

        $parentTerm->save();

        if (is_array($value)) {
          foreach ($value as $child) {
            $newChild = Term::create([
              'name' => $child,
              'vid' => $vocabulary,
              'parent' => $parentTerm->id()
            ]);

            $newChild->save();
          }
        }
      }
    }
  }
}

/**
 * Create Topics, Tags and Organizations taxonomy reference fields on Page content type
 */
function ncar_ucar_umbrella_update_8110()
{
  $entityType = 'node';
  $bundle = 'page';
  $fields = [
    'field_page_topics' => [
      'label' => 'Topics',
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'topics' => 'topics'
          ]
        ]
      ]
    ],
    'field_page_tags' => [
      'label' => 'Tags',
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'tags' => 'tags'
          ]
        ]
      ]
    ],
    'field_page_organizations' => [
      'label' => 'Organizations',
      'settings' => [
        'handler_settings' => [
          'target_bundles' => [
            'organizations' => 'organizations'
          ]
        ]
      ]
    ]
  ];

  $formDisplay = \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->load($entityType . '.' . $bundle . '.' . 'default');

  foreach ($fields as $field => $config) {
    $fieldStorage = FieldStorageConfig::loadByName($entityType, $field);

    if (!$fieldStorage) {
      $fieldStorage = FieldStorageConfig::create([
        'field_name' => $field,
        'entity_type' => $entityType,
        'type' => 'entity_reference',
        'cardinality' => -1,
        'settings' => ['target_type' => 'taxonomy_term']
      ]);

      $fieldStorage->save();
    }

    $fieldConfig = FieldConfig::loadByName($entityType, $bundle, $field);

    if (!$fieldConfig) {
      $fieldConfig = FieldConfig::create([
        'field_storage' => $fieldStorage,
        'bundle' => $bundle,
        'label' => $config['label'],
        'field_name' => $field,
        'entity_type' => $entityType,
        'settings' => $config['settings']
      ]);

      $fieldConfig->save();
    }

    $formDisplay->setComponent($field, array(
      'type' => 'entity_reference_autocomplete',
    ))
      ->save();
  }
}

/**
 * Update 2 admin blocks to be in content, not banner, region
 */
function ncar_ucar_umbrella_update_8111() {

  $default_theme = \Drupal::config('system.theme')->get('default');

  $update_blocks =  ['local_actions', 'local_tasks'];

  $new_region = 'content';

  foreach($update_blocks as $block_name) {
    $koru_block = Block::load($block_name);
    $theme_block = Block::load($default_theme . '_' . $block_name);

    if($koru_block) {
      $koru_block->setRegion($new_region);
      $koru_block->save();
    }
    if($theme_block) {
      $theme_block->setRegion($new_region);
      $theme_block->save();
    }
  }
}

/**
 * Add Large News Feed image style
 */
function ncar_ucar_umbrella_update_8112() {
  $style = ImageStyle::create(array('name' => 'large_news_feed', 'label' => 'Large News Feed (775x550)'));

  $configuration = array(
    'id' => 'image_scale_and_crop',
    'weight' => 0,
    'data' => array(
      'width' => 775,
      'height' => 550,
    ),
  );

  $effect = \Drupal::service('plugin.manager.image.effect')->createInstance($configuration['id'], $configuration);
  $style->addImageEffect($effect->getConfiguration());
  $style->save();
}

/**
 * Install the simplesamlphp_auth module
 */
function ncar_ucar_umbrella_update_8113() {

  $profile_config_dir = drupal_get_path('profile', 'ncar_ucar_umbrella') . '/config/install';

  \Drupal::service('module_installer')->install(['externalauth', 'simplesamlphp_auth']);

  $config_path = $profile_config_dir . '/simplesamlphp_auth.settings.yml';
  $data = Yaml::parse($config_path);
  \Drupal::configFactory()
    ->getEditable('simplesamlphp_auth.settings')
    ->setData($data)
    ->save(TRUE);
}

/**
 * Create 'Editor' role and set permissions on the role
 */
function ncar_ucar_umbrella_update_8114() {
  $permissions = array(
    'access administration pages',
    'access content overview',
    'access files overview',
    'access news feeds',
    'access site in maintenance mode',
    'access toolbar',
    'access tour',
    'administer nodes',
    'create url aliases',
    'use text format details_table',
    'use text format wysiwyg_editor',
    'view the administration theme',
  );

  $role = Role::create(['id' => 'editor', 'label' => 'Editor']);

  foreach ($permissions as $permission) {
    $role->grantPermission($permission);
  }

  $role->save();
}

/**
 * Install the UCAR Alert module and place the block
 */
function ncar_ucar_umbrella_update_8115() {

  \Drupal::service('module_installer')->install(['ucar_alert']);

  $default_theme = \Drupal::config('system.theme')->get('default');

  $block_name =  'ucar_alert_block';

  $new_region = 'header';

  $koru_block = Block::load($block_name);
  $theme_block = Block::load($default_theme . '_' . $block_name);

  if($koru_block) {
    $koru_block->setRegion($new_region);
    $koru_block->save();
  }
  if($theme_block) {
    $theme_block->setRegion($new_region);
    $theme_block->save();
  }

}

/**
 * Switch admin theme from seven to eight to fix bug 2906737
 */
function ncar_ucar_umbrella_update_8116() {

  // Make sure the theme is installed
  $theme_handler = \Drupal::service('theme_handler');
  $theme_handler->install(['eight']);

  $config_factory = \Drupal::configFactory();

  // Set it as the admin theme
  $config = $config_factory->getEditable('system.theme');
  $config->set('admin', 'eight')->save();

  //get blocks installed above so we can delete them
  $block_configs = \Drupal::database()
    ->query('SELECT `name` FROM {config} WHERE `name` LIKE :blocks;', [
      ':blocks' => 'block.block.eight_%'
    ])
    ->fetchAll();

  //delete wrong config
  foreach($block_configs as $config_name)
  {
    $config_factory->getEditable($config_name->name)->delete();
  }

  //set the correct blocks
  $profile_config_dir = drupal_get_path('profile', 'ncar_ucar_umbrella') . '/config/install';

  foreach(glob($profile_config_dir . '/block.block.eight_*.yml') as $config_file)
  {
    $data = Yaml::parse(file_get_contents($config_file));
    $name = basename($config_file, '.yml');
    $config_factory
      ->getEditable($name)
      ->setData($data)
      ->save(TRUE);
  }

}

/**
 * Make URL fields in the Text Only List component optional
 */
function ncar_ucar_umbrella_update_8117()
{
  $paragraphs = [
    'text_only_list' => [
      'entity' => 'paragraph',
      'field' => 'field_text_only_list_link'
    ],
    'text_only_item' => [
      'entity' => 'paragraph',
      'field' => 'field_text_only_item_link'
    ]
  ];

  foreach ($paragraphs as $paragraph => $field) {
    $fieldLink = FieldConfig::loadByName($field['entity'], $paragraph, $field['field']);

    if ($fieldLink instanceof FieldConfig && $fieldLink->isRequired()) {
      $fieldLink->setRequired(false)->save();
    }
  }
}

/**
 * Install Responsive Tables Filter and setup wysiwyg
 */
function ncar_ucar_umbrella_update_8118()
{
  \Drupal::service('module_installer')->install(['responsive_tables_filter']);

  $config_factory = \Drupal::configFactory();

  $profile_config_dir = drupal_get_path('profile', 'ncar_ucar_umbrella') . '/config/install';

  $configs = ['filter.format.wysiwyg_editor', 'editor.editor.wysiwyg_editor'];
  foreach($configs as $config)
  {
    $config_file = $profile_config_dir . '/' . $config . '.yml';
    $data = Yaml::parse(file_get_contents($config_file));
    $config_factory
      ->getEditable($config)
      ->setData($data)
      ->save(TRUE);
  }
}

/**
 * Uninstall responsive_tables_filter module and replace it with
 * the custom responsive_tables module. The custom module will use
 * data-labels instead of the tablesaw.js library.
 */
function ncar_ucar_umbrella_update_8119()
{
  $moduleHandler = \Drupal::service('module_handler');
  $moduleService = \Drupal::service('module_installer');

  if ($moduleHandler->moduleExists('responsive_tables') == 0) {
    $moduleService->install(['responsive_tables']);
  }

  $wysiwygEditorFilter = FilterFormat::load('wysiwyg_editor');
  $wysiwygEditorFilter->setFilterConfig('filter_responsive_tables_filter', ['status' => FALSE]);
  $wysiwygEditorFilter->setFilterConfig('filter_responsive_tables', ['status' => TRUE]);
  $wysiwygEditorFilter->save();

  if ($moduleHandler->moduleExists('responsive_tables_filter') == 1) {
    $moduleService->uninstall(['responsive_tables_filter']);
  }
}

/**
 * Update HTML filters to allow the data-label attribute on the
 * td element and the height and width attributes on the img element.
 */
function ncar_ucar_umbrella_update_8120()
{
  $imagePattern = '<img';
  $imageReplacement = '<img height width';

  $cellPattern = '<td';
  $cellReplacement = '<td data-label';

  $detailsTable = FilterFormat::load('wysiwyg_editor');
  $detailsTableHTML = $detailsTable->filters('filter_html')->settings['allowed_html'];
  $detailsTableHTML = str_replace($imagePattern, $imageReplacement, $detailsTableHTML);
  $detailsTableHTML = str_replace($cellPattern, $cellReplacement, $detailsTableHTML);

  $detailsTable->filters('filter_html')->settings['allowed_html'] = $detailsTableHTML;
  $detailsTable->save();
}

/**
 * Install Metatag module and set global values for favicons for NCAR and UCAR sites
 */
function ncar_ucar_umbrella_update_8121()
{
  \Drupal::service('module_installer')->install(['metatag', 'metatag_favicons', 'metatag_mobile']);

  $site = \Drupal::config('system.theme')->get('default');

  if($site == 'ncar' || $site == 'ucar') {
    $favicon_path = '/profiles/custom/ncar_ucar_umbrella/themes/custom/koru/libraries/koru-base/img/app-favicons/' . $site;

    $global_tags = [
      'apple_touch_icon' => $favicon_path . '/apple-icon-60x60.png',
      'apple_touch_icon_114x114' => $favicon_path . '/apple-icon-114x114.png',
      'apple_touch_icon_120x120' => $favicon_path . '/apple-icon-120x120.png',
      'apple_touch_icon_144x144' => $favicon_path . '/apple-icon-144x144.png',
      'apple_touch_icon_152x152' => $favicon_path . '/apple-icon-152x152.png',
      'apple_touch_icon_180x180' => $favicon_path . '/apple-icon-180x180.png',
      'apple_touch_icon_72x72' => $favicon_path . '/apple-icon-72x72.png',
      'apple_touch_icon_76x76' => $favicon_path . '/apple-icon-76x76.png',
      'icon_16x16' => $favicon_path . '/favicon-16x16.png',
      'icon_32x32' => $favicon_path . '/favicon-32x32.png',
      'icon_96x96' => $favicon_path . '/favicon-96x96.png',
      'shortcut_icon' => $favicon_path . '/favicon.ico',
      'android_manifest' => $favicon_path . '/manifest.json',
      'apple_mobile_web_app_capable' => 'yes',
      'apple_mobile_web_app_title' => strtoupper($site),
      'application_name' => strtoupper($site),
      'msapplication_square150x150logo' => $favicon_path . '/ms-icon-150x150.png',
      'msapplication_square310x310logo' => $favicon_path . '/ms-icon-310x310.png',
      'msapplication_square70x70logo' => $favicon_path . '/ms-icon-70x70.png',
    ];

    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('metatag.metatag_defaults.global');
    $config->set('tags', array_merge($config->get('tags'), $global_tags));
    $config->save(TRUE);

    $config_factory->getEditable($site .'.settings')->delete();
  }
}

/**
 * Set secure cookie config to true in simplesamlphp_auth module
 */
function ncar_ucar_umbrella_update_8122()
{
  $config = \Drupal::configFactory()->getEditable('simplesamlphp_auth.settings');
  $config->set('secure', true)->save(true);
}

/**
 * Add Category Heading field to TADL and TADP paragraphs and make title fields optional
 *
 * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
 */
function ncar_ucar_umbrella_update_8123()
{
  foreach(['tadp', 'tadl'] as $type)
  {
    $entity_type = 'paragraph';
    $bundle = $type . '_list';
    $field_name = 'field_' . $bundle . '_heading';

    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'string'
    ]);
    $field_storage->save();

    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => 'List Heading',
      'field_name' => $field_name,
      'entity_type' => $bundle
    ])->save();

    $entity_form_display = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('paragraph.' . $bundle . '.default');

    $entity_form_display->setComponent($field_name, [
        'type' => 'string_textfield',
        'weight' => 2
      ])->save();

    $entity_view_display = \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load('paragraph.' . $bundle . '.default');

    $entity_view_display->setComponent($field_name, [
        'label' => 'hidden',
        'type' => 'text_default',
        'weight' => 2
      ])->save();

    //make the title field optional
    $title_field = 'field_' . $bundle . '_title';

    $title_field_config = FieldConfig::loadByName('paragraph', $bundle, $title_field);

    if ($title_field_config instanceof FieldConfig && $title_field_config->isRequired()) {
      $title_field_config->setRequired(false)->save();
    }
  }
}

/**
 * Update the wysiwyg_editor text filter to allow <p> and <br> tags
 */
function ncar_ucar_umbrella_update_8124()
{
  $wysiwygEditor = FilterFormat::load('wysiwyg_editor');
  $wysiwygEditor->filters('filter_html')->settings['allowed_html'] .= ' <p> <br>';
  $wysiwygEditor->save();
}

/**
 * Allow class attribute on a tags, and add cta-link style to wysiwyg editor
 *
 * @throws \Drupal\Core\Entity\EntityStorageException
 */
function ncar_ucar_umbrella_update_8125()
{
  /** @var FilterFormat $editor */
  $wysiwyg = FilterFormat::load('wysiwyg_editor');
  $filter = $wysiwyg->filters('filter_html');
  $filter->settings['allowed_html'] = str_replace('<a href', '<a class href', $filter->settings['allowed_html']);
  $wysiwyg->save();

  /** @var \Drupal\editor\EditorInterface $editor */
  $editor = Editor::load('wysiwyg_editor');
  $settings = $editor->getSettings();
  if(!isset($settings['plugins']['stylescombo']['styles'])) {
    $settings['plugins']['stylescombo']['styles'] = '';
  }
  $settings['plugins']['stylescombo']['styles'] = "a.cta-link.large.white-on-color|Arrow Button\r\n" . $settings['plugins']['stylescombo']['styles'];

  $editor->setSettings($settings);
  $editor->save();
}

/**
 * Remove core search module and install umbrella search
 */
function ncar_ucar_umbrella_update_8126()
{
  $moduleHandler = \Drupal::service('module_handler');
  $moduleService = \Drupal::service('module_installer');
  if ($moduleHandler->moduleExists('search') == 1) {
    $moduleService->uninstall(['search']);
  }

  $moduleService->install(['umbrella_search']);
}

/**
 * Install related_news module and block
 */
function ncar_ucar_umbrella_update_8127()
{
  $module = 'related_news';

  \Drupal::service('module_installer')
    ->install([$module]);

  $default_theme = \Drupal::config('system.theme')->get('default');

  if($default_theme != 'koru') {
    $block_name =  str_replace('_', '', $module . '_block');

    Block::load($block_name)
      ->createDuplicateBlock($default_theme . '_' . $block_name, $default_theme)
      ->save();
  }
}

/**
 * Create the contact form
 */
function ncar_ucar_umbrella_update_8128()
{
  $contact_form = ContactForm::create([
    'id' => 'contact',
    'label' => 'Contact Us',
    'message' => 'Thanks for your message. We’ll look into it and get back to you.',
    'reply' => 'Thanks for your message. We’ll look into it and get back to you.',
    'recipients' => ['ucarcomm@ucar.edu'],
    'selected' => TRUE
  ]);

  $contact_form->save();
}

/**
 * Enable the captcha and recaptcha module and update their config
 */
function ncar_ucar_umbrella_update_8129()
{
  $moduleService = \Drupal::service('module_installer');

  $moduleService->install(['captcha']);
  $moduleService->install(['recaptcha']);

  $config_factory = \Drupal::configFactory();

  $captcha = $config_factory->getEditable('captcha.settings');
  $captcha->set('add_captcha_description', FALSE);
  $captcha->set('default_challenge', 'recaptcha/reCAPTCHA')->save();

  $recaptcha = $config_factory->getEditable('recaptcha.settings');
  $recaptcha->set('site_key', '6LcGSVsUAAAAADptoriMm-A-vLzY9zznW8w9OITj');
  $recaptcha->set('secret_key', '6LcGSVsUAAAAAACLWd4ueaVdGpNWpoOy-BDBvW5n')->save();

  $captcha_point = \Drupal::entityManager()->getStorage('captcha_point')->create([
    'id' => 'contact_message_contact_form',
    'formId' => 'contact_message_contact_form',
    'status' => TRUE,
    'captchaType' => 'recaptcha/reCAPTCHA',
  ]);

  $captcha_point->save();
}

/**
 * Set the default contact form to the custom contact form and the redirect to null.
 */
function ncar_ucar_umbrella_update_8130()
{
  $config_factory = \Drupal::configFactory();

  $contact_form = $config_factory->getEditable('contact.form.contact');
  $contact_form->set('redirect', NULL)->save();

  $contact_form = $config_factory->getEditable('contact.settings');
  $contact_form->set('default_form', 'contact')->save();
}

/**
 * Enable the mail system and sendgrid modules
 */
function ncar_ucar_umbrella_update_8131() {
  $moduleService = \Drupal::service('module_installer');

  $moduleService->install(['mailsystem']);
  $moduleService->install(['sendgrid_integration']);
}

/**
 * Enable the pantheon_advanced_page_cache module
 */
function ncar_ucar_umbrella_update_8132() {

  \Drupal::service('module_installer')
    ->install(['pantheon_advanced_page_cache']);
}

/**
 * Create front-page content type and add the spotlight paragraph type
 */
function ncar_ucar_umbrella_update_8133()
{
  $moduleService = \Drupal::service('module_installer');

  $moduleService->install(['umbrella_front_page']);
  $moduleService->install(['spotlight_paragraph']);
}

/**
 * Install the google_analytics module
 */
function ncar_ucar_umbrella_update_8134()
{
  \Drupal::service('module_installer')
    ->install(['google_analytics']);
}

/**
 * Change term fields in page content type to select lists from auto-complete
 */
function ncar_ucar_umbrella_update_8135()
{
  $config_factory = \Drupal::configFactory();

  $page_form = $config_factory->getEditable('core.entity_form_display.node.page.default');

  foreach(['field_page_organizations', 'field_page_tags', 'field_page_topics'] as $field) {
    $page_form->set("content.{$field}.type", 'options_select');
  }

  $page_form->save();
}

/**
 * Install the sitemap module
 */
function ncar_ucar_umbrella_update_8136()
{
  \Drupal::service('module_installer')
    ->install(['sitemap']);
}

/**
 * Install the sitemap module
 */
function ncar_ucar_umbrella_update_8137()
{
  \Drupal::service('module_installer')
    ->install(['umbrella_404']);
}

/**
 * Update the wysiwyg_editor text filter to allow <colgroup> and <col> tags
 */
function ncar_ucar_umbrella_update_8138()
{
  $wysiwygEditor = FilterFormat::load('wysiwyg_editor');
  $wysiwygEditor->filters('filter_html')->settings['allowed_html'] .= ' <colgroup> <col>';
  $wysiwygEditor->save();
}

/**
 * Install the metatag_verification module
 */
function ncar_ucar_umbrella_update_8139()
{
  \Drupal::service('module_installer')
    ->install(['metatag_verification']);
}

/**
 * Install the Simple XML Sitemap module
 */
function ncar_ucar_umbrella_update_8140()
{
  \Drupal::service('module_installer')
    ->install(['simple_sitemap']);
}

/**
 * Make the Page content type unpublished by default.
 */
function ncar_ucar_umbrella_update_8141()
{
  $override = BaseFieldOverride::loadbyName('node', 'page', 'status');

  if (!$override) {
    $entity = BaseFieldOverride::create([
      'field_name' => 'status',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Published',
      'default_value' => array (
        array (
          'value' => 0,
        )
      )
    ]);
    $entity->save();
  }
}

/**
 * Augment the editor role permissions so they can actually do stuff
 */
function ncar_ucar_umbrella_update_8142()
{
  //this submodule will filter items out of the admin menu role doesn't have access for
  \Drupal::service('module_installer')->install(['admin_toolbar_links_access_filter']);

  $permissions = [
    'administer menu',
    'create page content',
    'delete any page content',
    'delete own page content',
    'delete page revisions',
    'edit any page content',
    'edit own page content',
    'revert page revisions',
    'view own unpublished content',
    'view page revisions'
  ];

  $role = Role::load('editor');

  foreach ($permissions as $permission) {
    $role->grantPermission($permission);
  }

  $role->save();
}

/**
 * Default new pages to not be in a menu
 */
function ncar_ucar_umbrella_update_8143()
{
  if (\Drupal::service('module_handler')->moduleExists('menu_default')) {
    $page = NodeType::load('page');
    $page->setThirdPartySetting('menu_default', 'menu_default', 0);
    $page->save();
  }
}

/**
 * Update HTML filters to allow the col attribute to use a width.
 */
function ncar_ucar_umbrella_update_8144()
{
  $wysiwygEditor = FilterFormat::load('wysiwyg_editor');
  $wysiwygEditorHTML = str_replace('<col', '<col width', $wysiwygEditor->filters('filter_html')->settings['allowed_html']);
  $wysiwygEditor->filters('filter_html')->settings['allowed_html'] = $wysiwygEditorHTML;
  $wysiwygEditor->save();
}

/**
 * Update HTML filters to replace broken <col widthgroup with <colgroup.
 */
function ncar_ucar_umbrella_update_8145()
{
  $wysiwygEditor = FilterFormat::load('wysiwyg_editor');
  $wysiwygEditorHTML = str_replace('<col widthgroup', '<colgroup', $wysiwygEditor->filters('filter_html')->settings['allowed_html']);
  $wysiwygEditor->filters('filter_html')->settings['allowed_html'] = $wysiwygEditorHTML;
  $wysiwygEditor->save();
}

/**
 * Remove menu_default custom module in favor of page_alias_default as most functionality no longer needed
 */
function ncar_ucar_umbrella_update_8146()
{
  $moduleHandler = \Drupal::service('module_handler');
  $moduleService = \Drupal::service('module_installer');
  if ($moduleHandler->moduleExists('menu_default') == 1) {
    $moduleService->uninstall(['menu_default']);
  }
  $moduleService->install(['page_alias_default']);
}

/**
 * Augment the editor role permissions so they can add unpublished content to menus
 */
function ncar_ucar_umbrella_update_8147()
{
  $role = Role::load('editor');
  $role->grantPermission('bypass node access');
  $role->save();
}

/**
 * Install the redirect module
 */
function ncar_ucar_umbrella_update_8148()
{
  \Drupal::service('module_installer')
    ->install(['redirect']);
}

/**
 * Add subtitle field to thumbnail landscape paragraph type
 */
function ncar_ucar_umbrella_update_8149()
{
  $fieldStorage = FieldStorageConfig::loadByName('paragraph', 'field_tadl_item_subtitle');
  $fieldSubtitle = FieldConfig::loadByName('paragraph', 'tadl_item', 'field_tadl_item_subtitle');
  $fieldDescription = FieldConfig::loadByName('paragraph', 'tadl_item', 'field_tadl_item_description');
  $fieldLink = FieldConfig::loadByName('paragraph', 'tadl_item', 'field_tadl_item_link');
  $fieldImage = FieldConfig::loadByName('paragraph', 'tadl_item', 'field_tadl_item_image');

  if (empty($fieldStorage)) {
    $fieldStorage = FieldStorageConfig::create([
      'field_name' => 'field_tadl_item_subtitle',
      'entity_type' => 'paragraph',
      'type' => 'string'
    ]);

    $fieldStorage->save();
  }

  if (empty($fieldSubtitle)) {
    $fieldSubtitle = FieldConfig::create([
      'field_storage' => $fieldStorage,
      'bundle' => 'tadl_item',
      'label' => 'Item Subtitle',
      'field_name' => 'field_tadl_item_subtitle',
      'entity_type' => 'paragraph'
    ]);

    $fieldSubtitle->save();

    $formDisplay = \Drupal::entityTypeManager()
      ->getStorage('entity_form_display')
      ->load('paragraph' . '.' . 'tadl_item' . '.' . 'default');

    $formDisplay->setComponent('field_tadl_item_subtitle', array(
      'type' => 'string_textfield',
      'weight' => 1,
    ))
      ->save();

    $viewDisplay = \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load('paragraph' . '.' . 'tadl_item' . '.' . 'default');

    $viewDisplay->setComponent('field_tadl_item_subtitle', array(
      'label' => 'hidden',
      'type' => 'text_default',
      'weight' => 1,
    ))
      ->save();

    if ($fieldDescription) {
      $formDisplay->setComponent('field_tadl_item_description', array(
        'weight' => 2,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadl_item_description', array(
        'label' => 'hidden',
        'weight' => 2,
      ))
        ->save();
    }

    if ($fieldLink) {
      $formDisplay->setComponent('field_tadl_item_link', array(
        'weight' => 3,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadl_item_link', array(
        'label' => 'hidden',
        'weight' => 3,
      ))
        ->save();
    }

    if ($fieldImage) {
      $formDisplay->setComponent('field_tadl_item_image', array(
        'weight' => 4,
      ))
        ->save();

      $viewDisplay->setComponent('field_tadl_item_image', array(
        'label' => 'hidden',
        'weight' => 4,
      ))
        ->save();
    }
  }
}

/**
 * Install and configure the eu_cookie_compliance module
 */
function ncar_ucar_umbrella_update_8150()
{
  \Drupal::service('module_installer')
    ->install(['eu_cookie_compliance']);

  $config = \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings');
  $config->set('cookie_lifetime', 365);
  $config->set('cookie_name', 'gdpr_acknowledged');
  $config->set('method', 'default');
  $config->set('popup_agree_button_message', 'Acknowledge');
  $config->set('popup_bg_hex', '0779bf');
  $config->set('popup_info', array('value' => 'UCAR uses cookies to make our website function; however, UCAR cookies do not collect personal information about you. When using our website, you may encounter embedded content, such as YouTube videos and other social media links, that use their own cookies. To learn more about third-party cookies on this website, and to set your cookie preferences, click'));
  $config->set('popup_link', 'https://www.ucar.edu/privacy-policy')->save();
}

/**
 * Fix the link in the compliance module
 */
function ncar_ucar_umbrella_update_8151()
{
  $config = \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings');
  $config->set('show_disagree_button', true);
  $config->set('popup_disagree_button_message', 'here.');
  $config->set('popup_enabled', true);
  $config->set('popup_find_more_button_message', 'here.')->save();
}

/**
 * Disable consent by clicking in the compliance module and reset the cookie
 */
function ncar_ucar_umbrella_update_8152()
{
  $config = \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings');
  $config->set('popup_clicking_confirmation', false);
  $config->set('cookie_name', 'gdpr_acknowledged_1')->save();
}

/**
 * Update the eu compliance banner link
 */
function ncar_ucar_umbrella_update_8153()
{
  $config = \Drupal::configFactory()->getEditable('eu_cookie_compliance.settings');
  $config->set('popup_link', 'https://www.ucar.edu/cookie-other-tracking-technologies-notice')->save();
}

/**
 * Replace ucar single sign-on" button text
 */
function ncar_ucar_umbrella_update_8154()
{
  $config = \Drupal::configFactory()->getEditable('login_link_display_name');
  $config->set('show_disagree_button', true);
  $config->set('popup_disagree_button_message', 'here.');
  $config->set('popup_find_more_button_message', 'here.')->save();
}

/**
 * updating the user-login for umbrella sites
 */
 function ncar_ucar_umbrella_update_8154()
 {
    $config = \Drupal::configFactory()->getEditable('simplesamlphp_auth.settings')
    $config->set('default_login', false)->save();
 }
