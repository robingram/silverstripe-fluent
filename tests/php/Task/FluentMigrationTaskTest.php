<?php


namespace TractorCow\Fluent\Tests\Task;


use Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;
use TractorCow\Fluent\Extension\FluentExtension;
use TractorCow\Fluent\State\FluentState;
use TractorCow\Fluent\Task\FluentMigrationTask;
use TractorCow\Fluent\Tests\Task\FluentMigrationTaskTest\TranslatedDataObject;

/**
 * @TODO:
 * - test dataobject subclass
 * - test page
 * - test page subclass
 * - test generated queries for versioned dataobjects
 *
 *
 * Class FluentMigrationTaskTest
 * @package TractorCow\Fluent\Tests\Task
 */
class FluentMigrationTaskTest extends SapphireTest
{
    protected static $fixture_file = 'FluentMigrationTaskTest.yml';

    protected static $extra_dataobjects = [
        TranslatedDataObject::class
    ];

    /**
     * @useDatabase false
     */
    public function testTestDataObjectsHaveFluentExtensionApplied()
    {
        foreach (self::$extra_dataobjects as $className) {
            $instance = $className::create();
            $hasExtension = $instance->hasExtension(FluentExtension::class);
            $this->assertTrue($hasExtension, $className . ' should have FluentExtension applied');
        }

    }

    public function testFixturesAreSetupWithOldData()
    {
        $house = $this->objFromFixture(TranslatedDataObject::class, 'house');

        $id = $house->ID;

        $allFields = SQLSelect::create()
            ->setFrom(Config::inst()->get(TranslatedDataObject::class, 'table_name'))
            ->addWhere('ID = ' . $id)
            ->firstRow()
            ->execute();
        $record = $allFields->record();

        $this->assertEquals('A House', $record['Title_en_US']);
        $this->assertEquals('Something', $record['Name_en_US']);
        $this->assertEquals('Ein Haus', $record['Title_de_AT']);
        $this->assertEquals('Irgendwas', $record['Name_de_AT']);
    }

    public function testMigrationTaskMigratesDataObjectsWithoutVersioning()
    {
        $house = $this->objFromFixture(TranslatedDataObject::class, 'house');

        $this->assertFalse($this->hasLocalisedRecord($house, 'de_AT'), 'house should not exist in locale de_AT before migration');
        $this->assertFalse($this->hasLocalisedRecord($house, 'en_US'), 'house should not exist in locale de_AT before migration');

        $task = FluentMigrationTask::create();
        $task->run(null);

        $this->assertTrue($this->hasLocalisedRecord($house, 'de_AT'), 'house should exist in locale de_AT after migration');
        $this->assertTrue($this->hasLocalisedRecord($house, 'en_US'), 'house should exist in locale de_AT after migration');

        //check if all fields have been translated
        $id = $house->ID;
        $houseEN = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('en_US');

            return TranslatedDataObject::get()->byID($id);
        });
        $houseDE = FluentState::singleton()->withState(function ($newState) use ($id) {

            $newState->setLocale('de_AT');

            return TranslatedDataObject::get()->byID($id);
        });
        $this->assertEquals('Ein Haus', $houseDE->Title, 'German home should have translated Title');
        $this->assertEquals('Irgendwas', $houseDE->Name, 'German home should have translated Name');
        $this->assertEquals('A House', $houseEN->Title, 'English home should have translated Title');
        $this->assertEquals('Something', $houseEN->Name, 'English home should have translated Name');
    }

    public function testMigrationTaskCanRunSafelyASecondTime()
    {
        $baseTable = Config::inst()->get(TranslatedDataObject::class, 'table_name');
        $localisedTable = $baseTable . '_Localised';

        //there should be no localised fields when the test starts
        $localisedSelect = SQLSelect::create()
            ->setFrom($localisedTable);

        $this->assertEquals(0, $localisedSelect->count(), 'there should be no localised rows when the test starts');

        $task = FluentMigrationTask::create();
        $task->run(null);

        $countAfterMigration = $localisedSelect->count();
        $this->assertGreaterThan(0, $countAfterMigration, 'after task has run there should be localised rows');

        $task->run(null);

        $this->assertEquals($countAfterMigration, $localisedSelect->count(), 'after a second run there should be no new localised rows');
    }

    /**
     * @useDatabase false
     */
    public function testMigrationTaskBuildsOnlyQueryForBaseTableForUnverionedObjects()
    {
        Config::modify()->set('Fluent', 'locales', ['en_US', 'de_AT']);
        $tables = TranslatedDataObject::create()->getLocalisedTables();


        $queries = self::callMethod(FluentMigrationTask::create(), 'buildQueries', [$tables]);
        $this->assertArrayHasKey('de_AT', $queries, 'buildQueries should build queries for de_AT');

        $this->assertArrayHasKey('FluentTestDataObject_Localised', $queries['de_AT'], 'buildQueries should have key for base table');
        $this->assertArrayNotHasKey('FluentTestDataObject_Localised_Live', $queries['de_AT'], 'buildQueries should not have key for live table');
        $this->assertArrayNotHasKey('FluentTestDataObject_Localised_Versions', $queries['de_AT'], 'buildQueries should not have key for versions table');

    }

    /**
     * @useDatabase false
     */
    public function testGetLocales()
    {
        $locales = [
            'de_ch', 'en_foo'
        ];
        Config::modify()->set('Fluent', 'locales', $locales);

        $task = FluentMigrationTask::create();

        $this->assertEquals($locales, $task->getLocales(), 'getLocales() should get locales from old fluent config');

    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Fluent.locales is required
     * @useDatabase false
     */
    public function testGetLocalesThrowsExceptionWhenNoConfigIsFound()
    {
        Config::modify()->set('Fluent', 'locales', []);
        $task = FluentMigrationTask::create();
        $task->getLocales();
    }

    /**
     * Get a Locale field value directly from a record's localised database table, skipping the ORM
     *
     * taken from FluentExtensionTest
     *
     * @param DataObject $record
     * @param string $locale
     * @return boolean
     */
    protected function hasLocalisedRecord(DataObject $record, $locale)
    {
        $result = SQLSelect::create()
            ->setFrom($record->config()->get('table_name') . '_Localised')
            ->setWhere([
                'RecordID' => $record->ID,
                'Locale' => $locale,
            ])
            ->execute()
            ->first();

        return !empty($result);
    }

    /**
     * Helper to test private methods, see https://stackoverflow.com/a/8702347/4137738
     *
     * @param $obj
     * @param $name
     * @param array $args
     * @return mixed
     * @throws \ReflectionException
     */
    public static function callMethod($obj, $name, array $args = []) {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
