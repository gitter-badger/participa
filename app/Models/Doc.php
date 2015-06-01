<?php

namespace MXAbierto\Participa\Models;

use Illuminate\Database\Model\Collection;

use Illuminate\Database\Model\Model;

class Doc extends Model
{
    public static $timestamp = true;

    protected $index;
    protected $softDelete = true;

    const TYPE = 'doc';

    const SPONSOR_TYPE_INDIVIDUAL = "individual";
    const SPONSOR_TYPE_GROUP = "group";

    public function __construct()
    {
        parent::__construct();

        $this->index = Config::get('elasticsearch.annotationIndex');
    }

    public function getEmbedCode()
    {
        $dom = new \DOMDocument();

        $docSrc = URL::to('docs/embed', $this->slug);

        $insertElement = $dom->createElement('div');

        $containerElement = $dom->createElement('iframe');
        $containerElement->setAttribute('id', '__ogFrame');
        $containerElement->setAttribute('width', 300);
        $containerElement->setAttribute('height', 500);
        $containerElement->setAttribute('src', $docSrc);
        $containerElement->setAttribute('frameBorder', 0);

        $insertElement->appendChild($containerElement);

        return $dom->saveHtml($insertElement);
    }

    public function introtext()
    {
        return $this->hasMany('MXAbierto\Participa\Models\DocMeta')->where('meta_key', '=', 'intro-text');
    }

    public function dates()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Date');
    }

    public function canUserEdit($user)
    {
        $sponsor = $this->sponsor()->first();

        if ($user->hasRole('Admin')) {
            return true;
        }

        switch (true) {
            case $sponsor instanceof User:
                return $sponsor->id == $user->id && $sponsor->hasRole('Independent Sponsor');
            case $sponsor instanceof Group:
                return $sponsor->userHasRole($user, Group::ROLE_EDITOR) || $sponsor->userHasRole($user, Group::ROLE_OWNER);
                break;
            default:
                throw new \Exception("Unknown Sponsor Type");
        }

        return false;
    }

    public function sponsor()
    {
        $sponsor = $this->belongsToMany('MXAbierto\Participa\Models\Group')->first();

        if (!$sponsor) {
            return $this->belongsToMany('MXAbierto\Participa\Models\User');
        }

        return $this->belongsToMany('MXAbierto\Participa\Models\Group');
    }

    public function userSponsor()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\User');
    }

    public function groupSponsor()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\Group');
    }

    public function sponsorName()
    {
        $sponsor = $this->sponsor->first();
        if ($sponsor instanceof User) {
            $display_name = $sponsor->fname.' '.$sponsor->lname;
        } elseif ($sponsor instanceof Group) {
            $display_name = $sponsor->name;
        } else {
            $display_name = '';
        }

        return $display_name;
    }

    public function statuses()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\Status');
    }

    public function categories()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\Category');
    }

    public function comments()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Comment');
    }

    public function annotations()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Annotation');
    }

    public function getLink()
    {
        return URL::to('docs/'.$this->slug);
    }

    public function content()
    {
        return $this->hasOne('MXAbierto\Participa\Models\DocContent');
    }

    public function doc_meta()
    {
        return $this->hasMany('MXAbierto\Participa\Models\DocMeta');
    }

    public static function createEmptyDocument(array $params)
    {
        $defaults = [
            'content'     => "New Document Content",
            'sponsor'     => null,
            'sponsorType' => null,
        ];

        $params = array_replace_recursive($defaults, $params);

        if (is_null($params['sponsor'])) {
            throw new \Exception("Sponsor Param Required");
        }

        $document = new Doc();

        DB::transaction(function () use ($document, $params) {
            $document->title = $params['title'];
            $document->save();

            switch ($params['sponsorType']) {
                case static::SPONSOR_TYPE_INDIVIDUAL:
                    $document->userSponsor()->sync([$params['sponsor']]);
                    break;
                case static::SPONSOR_TYPE_GROUP:
                    $document->groupSponsor()->sync([$params['sponsor']]);
                    break;
                default:
                    throw new \Exception("Invalid Sponsor Type");
            }

            $template = new DocContent();
            $template->doc_id = $document->id;
            $template->content = "New Document Content";
            $template->save();

            $document->init_section = $template->id;
            $document->save();
        });

        Event::fire(MadisonEvent::NEW_DOCUMENT, $document);

        return $document;
    }

    public function save(array $options = [])
    {
        if (empty($this->slug)) {
            $this->slug = $this->getSlug();
        }

        return parent::save($options);
    }

    public function getSlug()
    {
        if (empty($this->title)) {
            throw new Exception("Can't get a slug - empty title");
        }

        return str_replace(
                    [' ', '.', ',', '#'],
                    ['-', '', '', ''],
                    strtolower($this->title));
    }

    public static function allOwnedBy($userId)
    {
        $rawDocs = DB::select(
            DB::raw(
                "SELECT docs.* FROM
					(SELECT doc_id
					   FROM doc_group, group_members
					  WHERE doc_group.group_id = group_members.group_id
					    AND group_members.user_id = ?
					UNION ALL
					 SELECT doc_id
					   FROM doc_user
					  WHERE doc_user.user_id = ?
				    ) DocUnion, docs
				  WHERE docs.id = DocUnion.doc_id
			   GROUP BY docs.id"
            ),
            [$userId, $userId]
        );

        $results = new Collection();

        foreach ($rawDocs as $row) {
            $obj = new static();

            foreach ($row as $key => $val) {
                $obj->$key = $val;
            }

            $results->add($obj);
        }

        return $results;
    }

    public static function getAllValidSponsors()
    {
        $userMeta = UserMeta::where('meta_key', '=', UserMeta::TYPE_INDEPENDENT_SPONSOR)
                            ->where('meta_value', '=', 1)
                            ->get();

        $groups = Group::where('status', '=', Group::STATUS_ACTIVE)
                        ->get();

        $results = new Collection();

        $userIds = [];

        foreach ($userMeta as $m) {
            $userIds[] = $m->user_id;
        }

        if (!empty($userIds)) {
            $users = User::whereIn('id', $userIds)->get();

            foreach ($users as $user) {
                $row = [
                        'display_name' => "{$user->fname} {$user->lname}",
                        'sponsor_type' => 'individual',
                        'id'           => $user->id,
                ];

                $results->add($row);
            }
        }

        foreach ($groups as $group) {
            $row = [
                    'display_name' => $group->display_name,
                    'sponsor_type' => 'group',
                    'id'           => $group->id,
            ];

            $results->add($row);
        }

        return $results;
    }

    public function setActionCount()
    {
        $es = self::esConnect();

        $params['index'] = $this->index;
        $params['type'] = 'annotation';
        $params['body']['term']['doc'] = (string) $this->id;

        $count = $es->count($params);

        $this->annotationCount = $count['count'];
    }

    public function get_file_path($format = 'markdown')
    {
        switch ($format) {
            case 'html' :
                $path = 'html';
                $ext = '.html';
                break;

            case 'markdown':
            default:
                $path = 'md';
                $ext = '.md';
        }

        $filename = $this->slug.$ext;
        $path = implode(DIRECTORY_SEPARATOR, [storage_path(), 'docs', $path, $filename]);

        return $path;
    }

    public function indexContent($doc_content)
    {
        $es = self::esConnect();

        File::put($this->get_file_path('markdown'), $doc_content->content);

        File::put($this->get_file_path('html'),
            Markdown::render($doc_content->content)
        );

        $body = [
            'id'      => $this->id,
            'content' => $doc_content->content,
        ];

        $params = [
            'index'    => $this->index,
            'type'     => self::TYPE,
            'id'       => $this->id,
            'body'     => $body,
        ];

        $results = $es->index($params);
    }

    public function get_content($format = null)
    {
        $path = $this->get_file_path($format);

        try {
            return File::get($path);
        } catch (Illuminate\Filesystem\FileNotFoundException $e) {
            $content = DocContent::where('doc_id', '=', $this->attributes['id'])->where('parent_id')->first()->content;

            if ($format == 'html') {
                $content = Markdown::render($content);
            }

            return $content;
        }
    }

    public static function search($query)
    {
        $es = self::esConnect();

        $params['index'] = Config::get('elasticsearch.annotationIndex');
        $params['type'] = self::TYPE;
        $params['body']['query']['filtered']['query']['query_string']['query'] = $query;

        return $es->search($params);
    }

    public static function esConnect()
    {
        $esParams['hosts'] = Config::get('elasticsearch.hosts');
        $es = new Elasticsearch\Client($esParams);

        return $es;
    }

    public static function findDocBySlug($slug = null)
    {
        //Retrieve requested document
        $doc = static::where('slug', $slug)
                     ->with('statuses')
                     ->with('userSponsor')
                     ->with('groupSponsor')
                     ->with('categories')
                     ->with('dates')
                     ->first();

        if (!isset($doc)) {
            return;
        }

        return $doc;
    }
}