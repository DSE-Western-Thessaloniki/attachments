<?php

namespace JMCameron\Plugin\Finder\Attachments\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\Component\Finder\Administrator\Indexer\Adapter;
use Joomla\Component\Finder\Administrator\Indexer\Helper;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Smart Search adapter for Joomla Categories.
 *
 * @since  2.5
 */
final class Attachments extends Adapter implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * The plugin identifier.
     *
     * @var    string
     * @since  2.5
     */
    protected $context = 'Attachments';

    /**
     * The extension name.
     *
     * @var    string
     * @since  2.5
     */
    protected $extension = 'com_attachments';

    /**
     * The sublayout to use when rendering the results.
     *
     * @var    string
     * @since  2.5
     */
    protected $layout = 'attachment';

    /**
     * The type of content that the adapter indexes.
     *
     * @var    string
     * @since  2.5
     */
    protected $type_title = 'Attachment';

    /**
     * The table name.
     *
     * @var    string
     * @since  2.5
     */
    protected $table = '#__attachments';

    /**
     * The field the published state is stored in.
     *
     * @var    string
     * @since  2.5
     */
    protected $state_field = 'state';

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   5.2.0
     */
    public static function getSubscribedEvents(): array
    {
        $parentEvents = [];
        // Joomla 4 Adapter class doesn't have a getSubscribedEvents method
        if (method_exists(Adapter::class, 'getSubscribedEvents')) {
            $parentEvents = call_user_func('parent::getSubscribedEvents');
        }

        return array_merge($parentEvents, [
            'onFinderAfterDelete' => 'onFinderAfterDelete',
            'onFinderAfterSave'   => 'onFinderAfterSave',
            'onFinderBeforeSave'  => 'onFinderBeforeSave',
            'onFinderChangeState' => 'onFinderChangeState',
        ]);
    }

    /**
     * Method to setup the indexer to be run.
     *
     * @return  boolean  True on success.
     *
     * @since   2.5
     */
    protected function setup()
    {
        return true;
    }

    /**
     * Method to remove the link information for items that have been deleted.
     *
     * @param   Event   $event  The event instance.
     *
     * @return  void.
     *
     * @since   2.5
     * @throws  \Exception on database error.
     */
    public function onFinderAfterDelete(Event $event): void
    {
        $context = $event->getArgument('context');
        $table = $event->getArgument('row');

        if ($context === 'com_categories.category') {
            $id = $table->id;
        } elseif ($context === 'com_finder.index') {
            $id = $table->link_id;
        } else {
            return;
        }

        // Remove item from the index.
        $this->remove($id);
    }

    /**
     * Smart Search after save content method.
     * Reindexes the link information for a category that has been saved.
     * It also makes adjustments if the access level of the category has changed.
     *
     * @param   Event   $event  The event instance.
     *
     * @return  void
     *
     * @since   2.5
     * @throws  \Exception on database error.
     */
    public function onFinderAfterSave(Event $event): void
    {
        $context = $event->getArgument('context');
        $row     = $event->getArgument('row');
        $isNew   = $event->getArgument('isNew');

        // We only want to handle categories here.
        if ($context === 'com_categories.category') {
            // Check if the access levels are different.
            if (!$isNew && $this->old_access != $row->access) {
                // Process the change.
                $this->itemAccessChange($row);
            }

            // Reindex the category item.
            $this->reindex($row->id);

            // Check if the parent access level is different.
            if (!$isNew && $this->old_cataccess != $row->access) {
                $this->categoryAccessChange($row);
            }
        }
    }

    /**
     * Smart Search before content save method.
     * This event is fired before the data is actually saved.
     *
     * @param   Event   $event  The event instance.
     *
     * @return  void
     *
     * @since   2.5
     * @throws  \Exception on database error.
     */
    public function onFinderBeforeSave(Event $event): void
    {
        $context = $event->getArgument('context');
        $row     = $event->getArgument('row');
        $isNew   = $event->getArgument('isNew');

        // We only want to handle categories here.
        if ($context === 'com_categories.category') {
            // Query the database for the old access level and the parent if the item isn't new.
            if (!$isNew) {
                $this->checkItemAccess($row);
                $this->checkCategoryAccess($row);
            }
        }
    }

    /**
     * Method to update the link information for items that have been changed
     * from outside the edit screen. This is fired when the item is published,
     * unpublished, archived, or unarchived from the list view.
     *
     * @param   Event   $event  The event instance.
     *
     * @return  void
     *
     * @since   2.5
     */
    public function onFinderChangeState(Event $event): void
    {
        $context = $event->getArgument('context');
        $pks     = $event->getArgument('pks');
        $value   = $event->getArgument('value');

        // We only want to handle categories here.
        if ($context === 'com_categories.category') {
            /*
             * The category published state is tied to the parent category
             * published state so we need to look up all published states
             * before we change anything.
             */
            foreach ($pks as $pk) {
                $pk    = (int) $pk;
                $query = clone $this->getStateQuery();

                $query->where($this->getDatabase()->quoteName('a.id') . ' = :plgFinderCategoriesId')
                    ->bind(':plgFinderCategoriesId', $pk, ParameterType::INTEGER);

                $this->getDatabase()->setQuery($query);
                $item = $this->getDatabase()->loadObject();

                // Translate the state.
                $state = null;

                if ($item->parent_id != 1) {
                    $state = $item->cat_state;
                }

                $temp = $this->translateState($value, $state);

                // Update the item.
                $this->change($pk, 'state', $temp);

                // Reindex the item.
                $this->reindex($pk);
            }
        }

        // Handle when the plugin is disabled.
        if ($context === 'com_plugins.plugin' && $value === 0) {
            $this->pluginDisable($pks);
        }
    }

    /**
     * Method to index an item. The item must be a Result object.
     *
     * @param   Result  $item  The item to index as a Result object.
     *
     * @return  void
     *
     * @since   2.5
     * @throws  \Exception on database error.
     */
    protected function index(Result $item)
    {
        // Check if the extension is enabled.
        if (ComponentHelper::isEnabled($this->extension) === false) {
            return;
        }

        // Extract the extension element
        $parts             = explode('.', $item->extension);
        $extension_element = $parts[0];

        // Check if the extension that owns the category is also enabled.
        if (ComponentHelper::isEnabled($extension_element) === false) {
            return;
        }

        $item->setLanguage();

        $extension = ucfirst(substr($extension_element, 4));

        // Initialize the item parameters.
        $item->params = new Registry($item->params);

        $item->metadata = new Registry($item->metadata);

        /*
         * Add the metadata processing instructions based on the category's
         * configuration parameters.
         */

        // Add the meta author.
        $item->metaauthor = $item->metadata->get('author');

        // Handle the link to the metadata.
        $item->addInstruction(Indexer::META_CONTEXT, 'link');
        $item->addInstruction(Indexer::META_CONTEXT, 'metakey');
        $item->addInstruction(Indexer::META_CONTEXT, 'metadesc');
        $item->addInstruction(Indexer::META_CONTEXT, 'metaauthor');
        $item->addInstruction(Indexer::META_CONTEXT, 'author');

        // Deactivated Methods
        // $item->addInstruction(Indexer::META_CONTEXT, 'created_by_alias');

        // Trigger the onContentPrepare event.
        $item->summary = Helper::prepareContent($item->summary, $item->params);

        // Create a URL as identifier to recognise items again.
        $item->url = $this->getUrl($item->id, $item->extension, $this->layout);

        /*
         * Build the necessary route information.
         * Need to import component route helpers dynamically, hence the reason it's handled here.
         */
        $class = $extension . 'HelperRoute';

        // Need to import component route helpers dynamically, hence the reason it's handled here.
        \JLoader::register($class, JPATH_SITE . '/components/' . $extension_element . '/helpers/route.php');

        if (class_exists($class) && method_exists($class, 'getCategoryRoute')) {
            $item->route = $class::getCategoryRoute($item->id, $item->language);
        } else {
            $class = 'Joomla\\Component\\' . $extension . '\\Site\\Helper\\RouteHelper';

            if (class_exists($class) && method_exists($class, 'getCategoryRoute')) {
                $item->route = $class::getCategoryRoute($item->id, $item->language);
            } else {
                // This category has no frontend route.
                return;
            }
        }

        // Get the menu title if it exists.
        $title = $this->getItemMenuTitle($item->url);

        // Adjust the title if necessary.
        if (!empty($title) && $this->params->get('use_menu_title', true)) {
            $item->title = $title;
        }

        // Translate the state. Categories should only be published if the parent category is published.
        $item->state = $this->translateState($item->state);

        // Get taxonomies to display
        $taxonomies = $this->params->get('taxonomies', ['type', 'language']);

        // Add the type taxonomy data.
        if (\in_array('type', $taxonomies)) {
            $item->addTaxonomy('Type', 'Category');
        }

        // Add the language taxonomy data.
        if (\in_array('language', $taxonomies)) {
            $item->addTaxonomy('Language', $item->language);
        }

        // Get content extras.
        Helper::getContentExtras($item);

        // Index the item.
        $this->indexer->index($item);
    }

    /**
     * Method to get the SQL query used to retrieve the list of content items.
     *
     * @param   mixed  $query  An object implementing QueryInterface or null.
     *
     * @return  QueryInterface  A database object.
     *
     * @since   2.5
     */
    protected function getListQuery($query = null)
    {
        $db = $this->getDatabase();

        // Check if we can use the supplied SQL query.
        $query = $query instanceof QueryInterface ? $query : $db->getQuery(true);

        $query->select(
            $db->quoteName(
                [
                    'a.id',
                    'a.filename',
                    'a.url',
                    'a.uri_type',
                    'a.url_valid',
                    'a.url_relative',
                    'a.url_verify',
                    'a.display_name',
                    'a.access',
                    'a.state',
                    'a.parent_type',
                    'a.parent_id',
                    'a.created_by',
                    'a.modified',
                    'a.modified_by'
                ]
            )
        )
            ->select(
                $db->quoteName(
                    [
                        'a.created',
                    ],
                    [
                        'start_date',
                    ]
                )
            )
            ->from($db->quoteName($this->table, 'a'));

        return $query;
    }

    /**
     * Method to get a SQL query to load the published and access states for
     * a category and its parents.
     *
     * @return  QueryInterface  A database object.
     *
     * @since   2.5
     */
    protected function getStateQuery()
    {
        $query = $this->getDatabase()->getQuery(true);

        $query->select(
            $this->getDatabase()->quoteName(
                [
                    'a.id',
                    'a.parent_id',
                    'a.access',
                ]
            )
        )
            ->select(
                $this->getDatabase()->quoteName(
                    [
                        'a.' . $this->state_field,
                        'c.published',
                        'c.access',
                    ],
                    [
                        'state',
                        'cat_state',
                        'cat_access',
                    ]
                )
            )
            ->from($this->getDatabase()->quoteName('#__categories', 'a'))
            ->join(
                'INNER',
                $this->getDatabase()->quoteName('#__categories', 'c'),
                $this->getDatabase()->quoteName('c.id') . ' = ' . $this->getDatabase()->quoteName('a.parent_id')
            );

        return $query;
    }
}
