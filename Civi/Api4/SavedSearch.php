<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */
namespace Civi\Api4;

/**
 * SavedSearch aka Smart Groups.
 *
 * Stores search parameters for populating smart groups with live results.
 *
 * @see https://docs.civicrm.org/user/en/latest/organising-your-data/smart-groups/
 * @searchable none
 * @since 5.24
 * @package Civi\Api4
 */
class SavedSearch extends Generic\DAOEntity {

  public static function permissions() {
    $permissions = parent::permissions();
    $permissions['get'] = ['access CiviCRM'];
    return $permissions;
  }

}
