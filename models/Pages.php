<?php

namespace app\models;

use app\components\simple_html_dom;
use app\helpers\Normalize;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * This is the model class for table "{{%pages}}".
 *
 * @property integer $id
 * @property integer $id_author
 * @property integer $id_type
 * @property string $title
 * @property string $alias
 * @property string $crumb
 * @property string $vcrumbs
 * @property string $breadcrumbs
 * @property string $content
 * @property string $meta_t
 * @property string $meta_k
 * @property string $meta_d
 * @property integer $created
 * @property integer $modified
 * @property integer $views
 * @property integer $ordering
 * @property integer $status
 * @property integer $is_sitemap
 * @property integer $is_auto
 */
class Pages extends \yii\db\ActiveRecord
{
    public $aliases = [];

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%pages}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['title', 'alias'], 'required'],
            [['crumb'], 'required', 'when' => function($model) {
                return $model->alias != 'index';
            }],
            [['content'], 'required', 'when' => function($model) {
                return !$model->is_auto;
            }],

            [['alias'], 'unique', 'message' => 'Такая ссылка уже занята'],

            [['id_author', 'created', 'modified', 'views', 'status', 'is_sitemap', 'is_auto', 'is_shared'], 'integer'],
            [['id_author', 'created', 'modified', 'views', 'status', 'is_sitemap', 'is_auto', 'is_shared'], 'default', 'value' => 0],
            [['is_shared'], 'default', 'value' => 1], // по умолчанию есть кнопки "поделиться"

            [['content'], 'string'],

            [['alias', 'title', 'crumb'], 'string', 'max' => 200],
            [['meta_t', 'meta_k', 'meta_d'], 'string', 'max' => 250],

            [['vcrumbs', 'breadcrumbs'], 'safe'],
        ];
    }

    public function getAuthor() {
        return $this->hasOne(Users::className(), ['id' => 'id_author']);
    }

    public static function getFilterList() {
        $list = self::find()->orderBy(['title' => SORT_ASC])->all();
        return $list ? ArrayHelper::map($list, 'id', 'title') : [];
    }

    public static function aliasById($page_id) {
        $page = self::findOne($page_id);
        return $page ? $page->alias : '#';
    }

    public function getBreadcrumbs() {

        $breadcrumbs = [];

        if ($this->breadcrumbs) {
            foreach ($this->breadcrumbs AS $page_id) {
                $crumb_page = self::findOne($page_id);
                if ($crumb_page) {
                    $breadcrumbs[] = [
                        'url' => Url::to([Normalize::fixAlias($crumb_page->alias)]),
                        'label' => $crumb_page->crumb
                    ];
                }

            }
        }

        if ($this->alias != 'index') {
            $breadcrumbs[] = ['label' => $this->crumb];
        }

        return $breadcrumbs;
    }

    public function getFixLinksContent() {

        if (!empty($this->content)) {
            // преобразование ссылок в контенте, вдруг сохранены неправильно
            $dom = new simple_html_dom();
            $dom->load($this->content);
            foreach ($dom->find('a') AS $a) {
                // убираем суффикс
                $a->href = trim(str_replace(Yii::$app->urlManager->suffix, '', $a->href), '/');
                // получаем полную ссылку
                $a->href = Url::to([Normalize::fixAlias($a->href)]);
            }

            $this->content = $dom->outertext;
        }

        return $this->content;
    }

    public function getFirstImage() {

        $image = '';

        if (!empty($this->content)) {
            // преобразование ссылок в контенте, вдруг сохранены неправильно
            $dom = new simple_html_dom();
            $dom->load($this->content);
            if ($dom->find('img')) {
                $image = $dom->find('img',0)->src;
                if (strpos($image, 'http') === false) {
                    $image = Yii::$app->request->hostInfo . $image;
                }
            }
        }

        return $image;
    }

    public function beforeValidate()
    {
        $this->id_author = Yii::$app->user->id;
        if ($this->isNewRecord) {
            $this->created = time();
        } else {
            $this->modified = time();
        }

        return parent::beforeValidate();
    }

    public function beforeSave($insert)
    {
        if (!empty($this->breadcrumbs) && is_array($this->breadcrumbs)) {
            $this->breadcrumbs = implode(';', array_filter($this->breadcrumbs));
        } else {
            $this->breadcrumbs = '';
        }

        if (!empty($this->vcrumbs) && is_array($this->vcrumbs)) {
            $this->vcrumbs = implode(';', array_filter($this->vcrumbs));
        } else {
            $this->vcrumbs = '';
        }

        if ($this->is_auto) $this->is_shared = 0;

        return parent::beforeSave($insert);
    }

    public function afterFind()
    {
        if (!empty($this->breadcrumbs)) {
            $this->breadcrumbs = array_filter(explode(';', $this->breadcrumbs));
        } else {
            $this->breadcrumbs = [];
        }

        if (!empty($this->vcrumbs)) {
            $this->vcrumbs = array_filter(explode(';', $this->vcrumbs));
        } else {
            $this->vcrumbs = [];
        }

        parent::afterFind();
    }

    public function afterDelete()
    {
        // удаляем из пункта меню
        Menu::updateAll(['page_id' => 0], ['page_id' => $this->id]);

        parent::afterDelete();
    }


    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'id_author' => 'Менеджер',
            'title' => 'Заголовок (Н1)',
            'alias' => 'Alias (URL)',
            'crumb' => 'Название в хлебной крошке',
            'breadcrumbs' => 'Основные хлебные крошки',
            'vcrumbs' => 'Дополнительные хлебные крошки',
            'content' => 'Содержимое',
            'meta_t' => 'Meta Title',
            'meta_k' => 'Meta Keywords',
            'meta_d' => 'Meta Description',
            'created' => 'Создан',
            'modified' => 'Изменен',
            'views' => 'Views',
            'status' => 'Статус',
            'is_sitemap' => 'Sitemap',
            'is_auto' => 'Auto',
            'is_shared' => 'Shared',
        ];
    }
}