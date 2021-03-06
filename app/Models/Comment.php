<?php

namespace MXAbierto\Participa\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model implements ActivityInterface
{
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'comments';

    const ACTION_LIKE = 'like';
    const ACTION_DISLIKE = 'dislike';
    const ACTION_FLAG = 'flag';

    public function doc()
    {
        return $this->belongsTo('MXAbierto\Participa\Models\Doc', 'doc_id');
    }

    public function user()
    {
        return $this->belongsTo('MXAbierto\Participa\Models\User', 'user_id');
    }

    public function comments()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Comment', 'parent_id');
    }

    public function likes()
    {
        $likes =  CommentMeta::where('comment_id', $this->id)
                    ->where('meta_key', '=', CommentMeta::TYPE_USER_ACTION)
                    ->where('meta_value', '=', static::ACTION_LIKE)
                    ->count();

        return $likes;
    }

    public function dislikes()
    {
        $dislikes = CommentMeta::where('comment_id', $this->id)
                             ->where('meta_key', '=', CommentMeta::TYPE_USER_ACTION)
                             ->where('meta_value', '=', static::ACTION_DISLIKE)
                             ->count();

        return $dislikes;
    }

    public function flags()
    {
        $flags = CommentMeta::where('comment_id', $this->id)
                         ->where('meta_key', '=', CommentMeta::TYPE_USER_ACTION)
                         ->where('meta_value', '=', static::ACTION_FLAG)
                         ->count();

        return $flags;
    }

    public function loadArray($userId = null)
    {
        $item = $this->toArray();
        $item['created'] = $item['created_at'];
        $item['updated'] = $item['updated_at'];
        $item['likes'] = $this->likes();
        $item['dislikes'] = $this->dislikes();
        $item['flags'] = $this->flags();
        $item['comments'] = [];

        return $item;
    }

    public function saveUserAction($userId, $action)
    {
        switch ($action) {
            case static::ACTION_LIKE:
            case static::ACTION_DISLIKE:
            case static::ACTION_FLAG:
                break;
            default:
                throw new \InvalidArgumentException('Invalid Action to Add');
        }

        $actionModel = CommentMeta::where('comment_id', '=', $this->id)
                                    ->where('user_id', '=', $userId)
                                    ->where('meta_key', '=', CommentMeta::TYPE_USER_ACTION)
                                    ->take(1)->first();

        if (is_null($actionModel)) {
            $actionModel = new CommentMeta();
            $actionModel->meta_key = CommentMeta::TYPE_USER_ACTION;
            $actionModel->user_id = $userId;
            $actionModel->comment_id = $this->id;
        }

        $actionModel->meta_value = $action;

        return $actionModel->save();
    }

    /**
     *   addOrUpdateComment.
     *
     *   Updates or creates a Comment
     *
     *   @param array $comment
     *
     *   @return Comment $obj with User relationship loaded
     */
    public function addOrUpdateComment(array $comment)
    {
        $comment['private'] = (!empty($comment['private']) && $comment['private'] != 'false') ? 1 : 0;

        $obj = new self();
        $obj->text = $comment['text'];
        $obj->user_id = $comment['user']['id'];
        $obj->doc_id = $this->doc_id;
        $obj->private = $comment['private'];

        if (isset($comment['id'])) {
            $obj->id = $comment['id'];
        }

        $obj->parent_id = $this->id;

        $obj->save();
        $obj->load('user');

        return $obj;
    }

    /**
     *   Construct link for Comment.
     *
     *   @param null
     *
     *   @return url
     */
    public function getLink()
    {
        $slug = \DB::table('docs')->where('id', $this->doc_id)->pluck('slug');

        return route('docs.doc', $slug).'#comment_'.$this->id;
    }

    /**
     *   Create RSS item for Comment.
     *
     *   @param null
     *
     *   @return array $item
     */
    public function getFeedItem()
    {
        $user = $this->user()->get()->first();

        $item['title'] = $user->fname.' '.$user->lname."'s Comment";
        $item['author'] = $user->fname.' '.$user->lname;
        $item['link'] = $this->getLink();
        $item['pubdate'] = $this->updated_at;
        $item['description'] = $this->text;

        return $item;
    }

    public static function loadComments($docId, $commentId, $userId)
    {
        $user = Auth::user();
        if (static::canUserEdit($user, $docId)) {
            $comments = static::withTrashed()->where('doc_id', '=', $docId)->with('user');
        } else {
            $comments = static::where('doc_id', '=', $docId)->with('user');

            $comments->where(function ($query) use ($user) {
                $user_id = ($user) ? $user->id : 0;

                $query->where('private', 0)
                ->orWhere('user_id', $user_id);
            });
        }

        if (!is_null($commentId)) {
            $comments->where('id', '=', $commentId);
        }

        $comments = $comments->get();

        $retval = [];
        foreach ($comments as $comment) {
            $retval[] = $comment->loadArray();
        }

        return $retval;
    }

    /**
     *   Include link to annotation when converted to array.
     *
     *   @param null
     *
     * @return parent::toArray()
     */
    public function toArray()
    {
        $this->link = $this->getLink();

        return parent::toArray();
    }

    public static function canUserEdit($user, $docId)
    {
        if (!$user) {
            return false;
        }

        $doc = Doc::find($docId);
        $comment = (empty($this)) ? new self() : $this;

        if ($comment->user_id == $user->id || $doc->canUserEdit($user)) {
            return true;
        }

        return false;
    }
}
