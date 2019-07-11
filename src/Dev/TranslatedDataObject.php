<?php


namespace TractorCow\Fluent\Dev;


use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLUpdate;
use TractorCow\Fluent\Extension\FluentExtension;

/**
 * Dataobject to test migration of old SS3 data to SS4
 *
 * Assuming we translated to de_AT and en_US
 *
 *
 * Class TranslatedDataObject
 * @package TractorCow\Fluent\Dev
 */
class TranslatedDataObject extends DataObject implements TestOnly
{
    private static $extensions = [
        FluentExtension::class
    ];

    private static $db = [
        'Title' => 'Varchar',
        'Name' => 'Varchar'
    ];

    private static $translate = [
        'Title',
        'Name'
    ];

    private static $table_name = 'FluentTestDataObject';

    /**
     * @var array
     */
    private static $old_fluent_fields = [
        'Title_en_US',
        'Title_de_AT',
        'Name_en_US',
        'Name_de_AT'
    ];

    public function onAfterWrite()
    {
        $update = SQLUpdate::create()
            ->setTable($this->baseTable());

        foreach (self::config()->get('old_fluent_fields') as $field) {
            $update->assign($field, $this->getField($field)); //value is set from fixtures in $this->record
            $update->execute();
        }
    }


    /**
     * Helper to get the old *_translationgroups table and the Locale field created
     */
    public function requireTable()
    {
        parent::requireTable();

        $baseDataClass = DataObject::getSchema()->baseDataClass($this->ClassName);
        if ($this->ClassName != $baseDataClass) {
            return;
        }

        $schemaManager = DB::get_schema();

        foreach (self::config()->get('old_fluent_fields') as $field) {
            $schemaManager->requireField($this->baseTable(), $field, 'VARCHAR(255) NULL DEFAULT NULL ');
        }
    }
}
