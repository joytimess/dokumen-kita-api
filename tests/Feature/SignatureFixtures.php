<?php

namespace Tests\Feature;

use App\Contracts\SignatureImageClassifierInterface;
use App\Enums\SignatureImageClassification;
use App\Models\DocumentVersion;
use App\Models\SignatureAsset;
use App\Models\SignaturePlaceholder;
use App\Models\User;
use App\Services\PublishService;
use App\Services\SignatureAssetService;
use Illuminate\Http\Testing\File;

trait SignatureFixtures
{
    use SigningFixtures;

    /** @param list<User> $signers */
    private function publishedVersion(User $owner, array $signers, int $placeholdersPerSigner = 1): DocumentVersion
    {
        $version = $this->preparedDraft($owner, array_map(fn (User $user): string => $user->email, $signers));
        foreach ($version->assignments()->get() as $assignment) {
            for ($index = 1; $index < $placeholdersPerSigner; $index++) {
                SignaturePlaceholder::factory()->create(['assignment_guid' => $assignment->guid, 'page_number' => 2]);
            }
        }
        $this->app->make(PublishService::class)->publish($owner, $version);
        foreach ($signers as $index => $signer) {
            $version->assignments()->where('sequence_number', $index + 1)->update(['user_guid' => $signer->guid, 'claimed_at' => now()]);
        }

        return $version->fresh();
    }

    private function signatureImage(string $kind = 'ink', string $format = 'png', int $width = 120, int $height = 60): File
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = $kind === 'transparent' || $kind === 'transparent-ink'
            ? imagecolorallocatealpha($image, 255, 255, 255, 127)
            : imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background);
        if ($kind === 'ink' || $kind === 'transparent-ink') {
            $ink = imagecolorallocatealpha($image, 10, 20, 30, 0);
            imageline($image, 10, 10, $width - 10, $height - 10, $ink);
            imageline($image, 10, $height - 10, $width - 10, 10, $ink);
        }
        if ($kind === 'solid') {
            imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        }
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image),
            'webp' => imagewebp($image),
            default => imagepng($image),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFileFactory::content('signature.'.$format, $bytes);
    }

    private function asset(User $owner): SignatureAsset
    {
        // Workflow fixtures use deterministic classification, not a live semantic provider.
        $this->mock(SignatureImageClassifierInterface::class)
            ->shouldReceive('classify')->andReturn(SignatureImageClassification::Signature);

        return $this->app->make(SignatureAssetService::class)->store($owner, $this->signatureImage(), 'drawn');
    }
}
