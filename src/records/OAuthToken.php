<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Class OAuthToken record.
 *
 * @property int            $id           ID
 * @property int            $userId       User ID
 * @property string         $provider     Provider
 * @property string         $accessToken  Access Token
 * @property string         $tokenType    Token Type
 * @property int            $expiresIn    Time left to expire
 * @property \DateTime      $expiryDate   Expiration Date
 * @property string         $refreshToken Refresh Token
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class OAuthToken extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%oauthtokens}}';
    }

    /**
     * Returns the OAuth Tokens’s user.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getUser(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
