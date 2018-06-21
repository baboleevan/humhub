<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\content\components;

use humhub\modules\topic\models\Topic;
use humhub\modules\topic\widgets\TopicLabel;
use humhub\widgets\Link;
use humhub\modules\user\models\User;
use Yii;
use yii\base\Exception;
use humhub\modules\content\widgets\WallEntry;
use humhub\widgets\Label;
use humhub\libs\BasePermission;
use humhub\modules\content\permissions\ManageContent;
use humhub\components\ActiveRecord;
use humhub\modules\content\models\Content;
use humhub\modules\content\interfaces\ContentOwner;
use yii\helpers\Html;

/**
 * ContentActiveRecord is the base ActiveRecord [[\yii\db\ActiveRecord]] for Content.
 *
 * Each instance automatically belongs to a [[\humhub\modules\content\models\Content]] record which is accessible via the content attribute.
 * This relations will be automatically added/updated and is also available before this record is inserted.
 *
 * The Content record/model holds all neccessary informations/methods like:
 * - Related ContentContainer (must be set before save!)
 * - Visibility
 * - Meta information (created_at, created_by, ...)
 * - Wall handling, archiving, pinning, ...
 *
 * Before adding a new ContentActiveRecord instance, you need at least assign an ContentContainer.
 *
 * Example:
 *
 * ```php
 * $post = new Post();
 * $post->content->container = $space;
 * $post->content->visibility = Content::VISIBILITY_PRIVATE; // optional
 * $post->message = "Hello world!";
 * $post->save();
 * ```
 *
 * Note: If the underlying Content record cannot be saved or validated an Exception will thrown.
 *
 * @property Content $content
 * @mixin \humhub\modules\user\behaviors\Followable
 * @property User $createdBy
 * @author Luke
 */
class ContentActiveRecord extends ActiveRecord implements ContentOwner
{

    /**
     * @see \humhub\modules\content\widgets\WallEntry
     * @var string the WallEntry widget class
     */
    public $wallEntryClass = "";

    /**
     * @var boolean should the originator automatically follows this content when saved.
     */
    public $autoFollow = true;

    /**
     * The stream channel where this content should displayed.
     * Set to null when this content should not appear on streams.
     *
     * @since 1.2
     * @var string|null the stream channel
     */
    protected $streamChannel = 'default';

    /**
     * Holds an extra manage permission by providing one of the following
     *
     *  - BasePermission class string
     *  - Array of type ['class' => '...', 'callback' => '...']
     *  - Anonymous function
     *  - BasePermission instance
     *
     * @var string permission instance
     * @since 1.2.1
     */
    protected $managePermission = ManageContent::class;

    /**
     * If set to true this flag will prevent default ContentCreated Notifications and Activities.
     * This can be used e.g. for sub content entries, whose creation is not worth mentioning.
     *
     * @var bool
     * @since 1.2.3
     */
    public $silentContentCreation = false;

    /**
     * @var Content used to cache the content relation in order to avoid the relation to be overwritten in the insert process
     * @see https://github.com/humhub/humhub/issues/3110
     * @since 1.3
     */
    protected $initContent;

    /**
     * ContentActiveRecord constructor accepts either an configuration array as first argument or an ContentContainerActiveRecord
     * and visibility settings.
     *
     * Use as follows:
     *
     * `$model = new MyContent(['myField' => 'value']);`
     *
     * or
     *
     * `$model = new MyContent($space1, Content::VISIBILITY_PUBLIC, ['myField' => 'value']);`
     *
     * or
     *
     * `$model = new MyContent($space1, ['myField' => 'value']);`
     *
     * @param array|ContentContainerActiveRecord $contentContainer either the configuration or contentcontainer
     * @param int $visibility
     * @param array $config
     */
    public function __construct($contentContainer = [], $visibility = null, $config = [])
    {
        if(is_array($contentContainer)) {
            parent::__construct($contentContainer);
        } else if($contentContainer instanceof ContentContainerActiveRecord) {
            $this->content->setContainer($contentContainer);
            if(is_array($visibility)) {
                $config = $visibility;
            } else if($visibility !== null) {
                $this->content->visibility = $visibility;
            }
            parent::__construct($config);
        } else {
            parent::__construct([]);
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->attachBehavior('FollowableBehavior', \humhub\modules\user\behaviors\Followable::className());
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        /**
         * Ensure there is always a corresponding Content record
         * @see Content
         */
        if ($name == 'content') {
            $content = $this->initContent = (empty($this->initContent)) ? parent::__get('content') : $this->initContent;

            if(!$content) {
                $content = $this->initContent =  new Content();
            }

            if(!$this->isRelationPopulated('content')) {
                $this->populateRelation('content', $content);
            }

            return $content;
        }
        return parent::__get($name);
    }

    /**
     * Returns the name of this type of content.
     * You need to override this method in your content implementation.
     *
     * @return string the name of the content
     */
    public function getContentName()
    {
        return $this->className();
    }

    /**
     * Can be used to define an icon for this content type e.g.: 'fa-calendar'.
     * @return string
     */
    public function getIcon()
    {
        return null;
    }

    /**
     * Returns either Label widget instances or strings.
     *
     * Subclasses should call `paren::getLabels()` as follows:
     *
     * ```php
     * public function getLabels($labels = [], $includeContentName = true)
     * {
     *    return parent::getLabels([Label::info('someText')->sortOrder(5)]);
     * }
     * ```
     *
     * @param array $result
     * @param bool $includeContentName
     * @return Label[]|\string[] content labels used for example in wallentrywidget
     */
    public function getLabels($labels = [], $includeContentName = true)
    {
        if ($this->content->isPinned()) {
            $labels[] = Label::danger(Yii::t('ContentModule.widgets_views_label', 'Pinned'))->sortOrder(100);
        }

        if($this->content->isArchived()) {
            $labels[] = Label::warning(Yii::t('ContentModule.widgets_views_label', 'Archived'))->sortOrder(200);
        }

        if ($this->content->isPublic()) {
            $labels[] = Label::info(Yii::t('ContentModule.widgets_views_label', 'Public'))->sortOrder(300);
        }

        if ($includeContentName) {
            $labels[] = Label::defaultType($this->getContentName())->icon($this->getIcon())->sortOrder(400);
        }

        foreach (Topic::findByContent($this->content)->all() as $topic) {
            $labels[] = TopicLabel::forTopic($topic);
        }

        return Label::sort($labels);
    }

    /**
     * Returns a description of this particular content.
     *
     * This will be used to create a text preview of the content record. (e.g. in Activities or Notifications)
     * You need to override this method in your content implementation.
     *
     * @return string description of this content
     */
    public function getContentDescription()
    {
        return "";
    }

    /**
     * Returns the $managePermission settings interpretable by an PermissionManager instance.
     *
     * @since 1.2.1
     * @see ContentActiveRecord::$managePermission
     * @return null|object
     */
    public function getManagePermission()
    {
        if(!$this->hasManagePermission()) {
            return null;
        } else if(is_string($this->managePermission)) { // Simple Permission class specification
            return $this->managePermission;
        } else if(is_array($this->managePermission)) {
            if(isset($this->managePermission['class'])) { // ['class' => '...', 'callback' => '...']
                $handler = $this->managePermission['class'].'::'.$this->managePermission['callback'];
                return call_user_func($handler, $this);
            } else { // Simple Permission array specification
                return $this->managePermission;
            }
        } else if(is_callable($this->managePermission)) { // anonymous function
            return $this->managePermission($this);
        } else if($this->managePermission instanceof BasePermission) {
            return $this->managePermission;
        } else {
            return null;
        }
    }

    /**
     * Determines weather or not this records has an additional managePermission set.
     *
     * @since 1.2.1
     * @return boolean
     */
    public function hasManagePermission()
    {
        return !empty($this->managePermission);
    }

    /**
     * Returns the wall output widget of this content.
     *
     * @param array $params optional parameters for WallEntryWidget
     * @return string
     */
    public function getWallOut($params = [])
    {
        $wallEntryWidget = $this->getWallEntryWidget();
        if ($wallEntryWidget !== null) {
            Yii::configure($wallEntryWidget, $params);
            return $wallEntryWidget->renderWallEntry();
        }
        return "";
    }

    /**
     * Returns the assigned wall entry widget instance
     *
     * @return null|\humhub\modules\content\widgets\WallEntry for this class by wallEntryClass property , null will be
     * returned if this wallEntryClass is empty
     */
    public function getWallEntryWidget()
    {
        if (is_subclass_of($this->wallEntryClass, WallEntry::class) ) {
            $class = $this->wallEntryClass;
            $widget = new $class;
            $widget->contentObject = $this;
            return $widget;
        } else if(!empty($this->wallEntryClass)) {
            $class = $this->wallEntryClass;
            $widget = new $class;
            return $widget;
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (!$this->content->validate()) {
            throw new Exception(
                'Could not validate associated Content record! (' . $this->content->getErrorMessage() . ')'
            );
        }

        $this->content->setAttribute('stream_channel', $this->streamChannel);
        return parent::beforeSave($insert);
    }

    /**
     * @inheritdoc
     */
    public function afterSave($insert, $changedAttributes)
    {
        // Auto follow this content
        if ($this->autoFollow) {
            $this->follow($this->content->created_by);
        }

        // Set polymorphic relation
        if ($insert) {
            $this->populateRelation('content', $this->initContent);
            $this->content->object_model = static::getObjectModel();
            $this->content->object_id = $this->getPrimaryKey();
        }

        // Always save content
        $this->content->save();

        parent::afterSave($insert, $changedAttributes);
    }

    public static function getObjectModel() {
        return static::class;
    }

    /**
     * @inheritdoc
     */
    public function afterDelete()
    {

        $content = Content::findOne(['object_id' => $this->id, 'object_model' => static::getObjectModel()]);
        if ($content !== null) {
            $content->delete();
        }

        parent::afterDelete();
    }

    /**
     * @return \humhub\modules\user\models\User the owner of this content record
     */
    public function getOwner()
    {
        return $this->content->createdBy;
    }

    /**
     * Checks if the given user or the current logged in user if no user was given, is the owner of this content
     * @param null $user
     * @return bool
     * @since 1.3
     */
    public function isOwner($user = null)
    {
        if (!$user && !Yii::$app->user->isGuest) {
            $user = Yii::$app->user->getIdentity();
        } else if (!$user) {
            return false;
        }

        return $this->content->created_by === $user->getId();

    }

    /**
     * Related Content model
     *
     * @return \yii\db\ActiveQuery|ActiveQueryContent
     */
    public function getContent()
    {
        return $this->hasOne(Content::className(), ['object_id' => 'id'])
            ->andWhere(['content.object_model' => static::getObjectModel()]);
    }

    /**
     * Returns an ActiveQueryContent to find content.
     *
     * @inheritdoc
     * @return ActiveQueryContent
     */
    public static function find()
    {
        return new ActiveQueryContent(static::getObjectModel());
    }
}

?>
