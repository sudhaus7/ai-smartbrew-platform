<?php
declare(strict_types=1);


namespace Symfony\AI\Platform\Bridge\Smartbrew;

use Symfony\AI\Platform\Bridge\OpenAi\Whisper\AudioNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\OpenResponsesContract;
use Symfony\AI\Platform\Contract;

class SmartbrewContract extends Contract {
    public static function create(array $normalizers = []): Contract
    {
        return parent::create($normalizers);
    }
}
