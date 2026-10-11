<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiRequestException;
use App\Services\Ai\AiVideoInput;
use Tests\TestCase;

class AiNativeRemainingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($binary = getenv('AI_TEST_FFMPEG_BINARY')) config(['ai.media_ffmpeg_binary' => $binary]);
    }

    private function model(string $name): AiModel
    {
        $definitions=json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')),true);
        $model = new AiModel(['slug'=>'example-model','upstream_model'=>$name,'category'=>'video',
            'capabilities'=>['native_tariff_hash'=>$definitions['deepinfra:'.$name]['tariff_hash']]]);
        $model->setRelation('provider',new AiProvider(['slug'=>'deepinfra']));
        return $model;
    }

    private function image(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    }

    private function audio(): string
    {
        $pcm = str_repeat("\0", 32000);
        return 'data:audio/wav;base64,'.base64_encode('RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($pcm)).$pcm);
    }

    private function video(): string
    {
        return 'data:video/mp4;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4')));
    }

    public function test_mp4_sample_timing_and_dimensions_are_read_before_decoder_execution(): void
    {
        $bytes=file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4'));
        $info=app(AiVideoInput::class)->inspect($bytes);
        $this->assertSame('1.000000',$info['seconds']);
        $this->assertSame(10,$info['frames']);
        $this->assertSame(320,$info['width']);
        $this->assertSame(240,$info['height']);
        foreach ([substr($bytes,0,100),$bytes.'trailer','not-an-mp4'] as $invalid) {
            try { app(AiVideoInput::class)->inspect($invalid); $this->fail('Malformed input was admitted.'); }
            catch (AiRequestException $e) { $this->assertSame('invalid_payload',$e->errorCode); }
        }
    }

    public function test_lying_mp4_duration_is_rejected_without_trusting_movie_header(): void
    {
        $bytes=file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4'));
        $offset=strpos($bytes,'mdhd');
        $bytes=substr_replace($bytes,pack('N',1),$offset+4+16,4);
        $this->expectException(AiRequestException::class);
        app(AiVideoInput::class)->inspect($bytes);
    }

    public function test_real_full_decode_is_required_and_temporary_media_is_removed(): void
    {
        if (!AiVideoInput::available()) $this->markTestSkipped('Trusted FFmpeg binary is not configured in this test environment.');
        $bytes=file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4'));
        $before=glob(storage_path('app/private/ai-media/tmp/input-*'))?:[];
        $info=app(AiVideoInput::class)->validate($bytes);
        $this->assertSame(10,$info['frames']);
        $this->assertSame('1.000000',$info['seconds']);
        $this->assertSame($before,glob(storage_path('app/private/ai-media/tmp/input-*'))?:[]);
        // Leave valid container metadata while corrupting the encoded H.264 samples.
        $offset=strpos($bytes,'mdat')+4;
        $invalid=substr_replace($bytes,str_repeat("\xff",strlen($bytes)-$offset),$offset);
        $this->expectException(AiRequestException::class);
        app(AiVideoInput::class)->validate($invalid);
    }

    public function test_video_capability_closes_when_decoder_is_missing(): void
    {
        config(['ai.media_ffmpeg_binary'=>'/does-not-exist/ffmpeg']);
        $this->assertNull(AiNative::definition($this->model('Bria/video_remove_background')));
        $this->assertNotNull(AiNative::definition($this->model('Wan-AI/Wan2.7-R2V')));
    }

    public function test_coded_dimensions_must_match_movie_track_dimensions(): void
    {
        if (!AiVideoInput::available()) $this->markTestSkipped('Trusted FFmpeg binary is not configured in this test environment.');
        $bytes=file_get_contents(base_path('tests/Fixtures/ai/one-second-h264.mp4'));
        $offset=strpos($bytes,'tkhd');
        $boxLength=unpack('N',substr($bytes,$offset-4,4))[1];
        $bytes=substr_replace($bytes,pack('N',640*65536),$offset-4+$boxLength-8,4);
        $this->assertSame(640,app(AiVideoInput::class)->inspect($bytes)['width']);
        $this->expectException(AiRequestException::class);
        app(AiVideoInput::class)->validate($bytes);
    }

    public function test_flux_prices_account_for_first_extra_and_each_reference_megapixel(): void
    {
        $native=app(AiNative::class);
        $pro=$this->model('black-forest-labs/FLUX-2-pro');
        $max=$this->model('black-forest-labs/FLUX-2-max');
        $this->assertSame(30000,$native->prepare(['prompt'=>'A cube','width'=>800,'height'=>800],$pro)->native['max_cost_micro']);
        $this->assertSame(45000,$native->prepare(['prompt'=>'A cube','width'=>1024,'height'=>1024],$pro)->native['max_cost_micro']);
        $this->assertSame(60000,$native->prepare(['prompt'=>'A cube','input_image'=>$this->image()],$pro)->native['max_cost_micro']);
        $this->assertSame(130000,$native->prepare(['prompt'=>'A cube','input_image'=>$this->image()],$max)->native['max_cost_micro']);
        $this->expectException(AiRequestException::class);
        $native->prepare(['prompt'=>'A cube','input_image'=>'https://example.test/a.png'],$pro);
    }

    public function test_flux_documented_image_url_and_scientific_cost_are_parsed_without_invented_tokens(): void
    {
        $raw='{"status":"ok","image_url":"https://media.example.test/generated.jpg","request_id":"example-request","inference_status":{"status":"succeeded","runtime_ms":6950,"cost":3e-2,"tokens_generated":0,"tokens_input":0,"output_length":1}}';
        $parsed=AiNative::parse(json_decode($raw,true,flags:JSON_THROW_ON_ERROR),$raw);
        $this->assertSame('3e-2',$parsed['estimated_cost']);
        $this->assertTrue($parsed['cost_only']);
        $this->assertNull($parsed['prompt']);
        $this->assertNull($parsed['completion']);
        $this->assertSame(6950,$parsed['native_usage']['runtime_ms']);
        $this->assertSame(1,$parsed['native_usage']['output_length']);
        foreach (['request_moderated','content_moderated','error','unknown'] as $status) {
            $body=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);$body['status']=$status;
            $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
        }
        foreach (['unknown','queued','running','failed'] as $status) {
            $body=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);$body['inference_status']['status']=$status;
            $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
        }
        foreach ([null,'',[],true] as $image) {
            $body=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);$body['image_url']=$image;
            $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
        }
        $body=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);$body['inference_status']['cost']='0.03invalid';
        $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
    }

    public function test_seedance_video_tokens_use_pixels_and_frames_and_deny_automatic_duration(): void
    {
        $native=app(AiNative::class);
        foreach (['ByteDance/Seedance-1.5-Pro'=>121534,'ByteDance/Seedance-2.0'=>425368] as $name=>$cost) {
            $model=$this->model($name);
            $prepared=$native->prepare(['prompt'=>'A cube','duration'=>5],$model);
            $this->assertSame($cost,$prepared->native['max_cost_micro']);
            $this->assertSame('480p',$prepared->body()['resolution']);
            $this->assertSame('16:9',$prepared->body()['aspect_ratio']);
            foreach ([['duration'=>-1],['duration'=>11],['aspect_ratio'=>'adaptive'],['resolution'=>'1080p']] as $input) {
                try { $native->prepare(['prompt'=>'A cube']+$input,$model); $this->fail('Unbounded video input was admitted.'); }
                catch (AiRequestException $e) { $this->assertSame('invalid_payload',$e->errorCode); }
            }
        }
    }

    public function test_documented_bria_mask_and_veo_video_results_require_completed_output(): void
    {
        $state=['status'=>'succeeded','cost'=>'0.14','output_length'=>1];
        foreach ([['status'=>'COMPLETED','video_url'=>null,'mask_url'=>'https://media.example.test/mask.mp4'],
            ['status'=>'ok','videos'=>['https://media.example.test/video.mp4']],
            ['status'=>'ok','videos'=>'https://media.example.test/video.mp4'],
            ['status'=>'ok','video_url'=>'https://media.example.test/video.mp4','out_tokens'=>50640]] as $output) {
            $body=$output+['inference_status'=>$state];
            $parsed=AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR));
            $this->assertSame('0.14',$parsed['estimated_cost']);
            $this->assertTrue($parsed['cost_only']);
            $this->assertNull($parsed['completion']);
            if (isset($output['out_tokens'])) $this->assertSame(50640,$parsed['native_usage']['out_tokens']);
            foreach (['ERROR','error','cancelled','processing','unknown'] as $status) {
                $body['status']=$status;
                $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
            }
        }
        foreach ([[],[''],[true],[['url'=>'x']],['not-a-list'=>'x'],null] as $videos) {
            $body=['status'=>'ok','videos'=>$videos,'inference_status'=>$state];
            $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
        }
        foreach (['',[],true,null] as $mask) {
            $body=['status'=>'COMPLETED','mask_url'=>$mask,'inference_status'=>$state];
            $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
        }
        foreach (['','  ',null] as $mask) {
            $body=['status'=>'COMPLETED','video_url'=>'https://media.example.test/video.mp4','mask_url'=>$mask,'inference_status'=>$state];
            $this->assertSame('0.14',AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR))['estimated_cost']);
        }
        $body=['status'=>'COMPLETED','mask_url'=>'  ','inference_status'=>$state];
        $this->assertNull(AiNative::parse($body,json_encode($body,JSON_THROW_ON_ERROR)));
    }

    public function test_avatar_reservation_uses_actual_pcm_duration_and_blocks_generated_speech(): void
    {
        $native=app(AiNative::class); $model=$this->model('PrunaAI/p-video-avatar');
        $prepared=$native->prepare(['image'=>$this->image(),'audio'=>$this->audio()],$model);
        $this->assertSame(90000,$prepared->native['max_cost_micro']);
        $this->expectException(AiRequestException::class);
        $native->prepare(['image'=>$this->image(),'voice_script'=>'A duration with no hard ceiling.'],$model);
    }

    public function test_wan_reference_objects_validate_types_counts_and_remote_urls(): void
    {
        $native=app(AiNative::class); $model=$this->model('Wan-AI/Wan2.7-R2V');
        $prepared=$native->prepare(['prompt'=>'Image 1 walks','media'=>[['type'=>'reference_image','url'=>$this->image()]],'duration'=>5],$model);
        $this->assertSame(750000,$prepared->native['max_cost_micro']);
        foreach ([[],[['type'=>'first_frame','url'=>$this->image()]],[['type'=>'reference_image','url'=>'http://127.0.0.1']],
            [['type'=>'reference_video','url'=>$this->image()]],[['type'=>'reference_image','url'=>$this->image(),'webhook'=>'x']],
            array_fill(0,6,['type'=>'reference_image','url'=>$this->image()])] as $media) {
            try { $native->prepare(['prompt'=>'A cube','media'=>$media],$model); $this->fail('Unsafe or undocumented reference media was admitted.'); }
            catch (AiRequestException $e) { $this->assertSame('invalid_payload',$e->errorCode); }
        }
    }

    public function test_bria_duration_and_mask_points_use_fully_decoded_video(): void
    {
        if (!AiVideoInput::available()) $this->markTestSkipped('Trusted FFmpeg binary is not configured in this test environment.');
        $native=app(AiNative::class);
        foreach (['video_remove_background'=>100000,'video_foreground_mask'=>280000,'video_increase_resolution'=>100000,
            'video_mask_by_prompt'=>280000,'video_mask_by_key_points'=>280000,'video_eraser'=>100000] as $name=>$cost) {
            $body=['video'=>$this->video()];
            if ($name==='video_mask_by_prompt') $body['prompt']='A cube';
            if ($name==='video_eraser') $body['mask']=$this->video();
            if ($name==='video_mask_by_key_points') $body['key_points']=[['type'=>'positive','x'=>1,'y'=>1]];
            $prepared=$native->prepare($body,$this->model('Bria/'.$name));
            $this->assertSame($cost,$prepared->native['max_cost_micro']);
        }
        $this->expectException(AiRequestException::class);
        $native->prepare(['video'=>$this->video(),'key_points'=>[['type'=>'positive','x'=>320,'y'=>1]]],$this->model('Bria/video_mask_by_key_points'));
    }

    public function test_bria_five_second_editing_limits_reject_longer_fully_decoded_input(): void
    {
        if (!AiVideoInput::available()) $this->markTestSkipped('Trusted FFmpeg binary is required.');
        $bytes=file_get_contents(base_path('tests/Fixtures/ai/six-second-h264.mp4'));
        $this->assertSame('6.000000',app(AiVideoInput::class)->validate($bytes)['seconds']);
        $video='data:video/mp4;base64,'.base64_encode($bytes);
        $native=app(AiNative::class);
        foreach (['video_eraser','video_mask_by_prompt','video_mask_by_key_points'] as $name) {
            $body=['video'=>$video];
            if ($name==='video_eraser') $body['mask']=$video;
            if ($name==='video_mask_by_prompt') $body['prompt']='A cube';
            if ($name==='video_mask_by_key_points') $body['key_points']=[['type'=>'positive','x'=>1,'y'=>1]];
            try { $native->prepare($body,$this->model('Bria/'.$name));$this->fail('Documented five-second limit was exceeded.'); }
            catch (AiRequestException $e) { $this->assertSame('invalid_payload',$e->errorCode); }
        }
        $this->assertSame(350000,$native->prepare(['video'=>$video],$this->model('Bria/video_increase_resolution'))->native['max_cost_micro']);
    }

    public function test_bria_mask_resolution_and_upscale_output_geometry_are_validated_before_spending(): void
    {
        if (!AiVideoInput::available()) $this->markTestSkipped('Trusted FFmpeg binary is required.');
        $native=app(AiNative::class);
        $wide='data:video/mp4;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/ai/wide-one-second-h264.mp4')));
        foreach (['video_eraser','video_mask_by_prompt','video_mask_by_key_points'] as $name) {
            $body=['video'=>$wide];
            if ($name==='video_eraser') $body['mask']=$wide;
            if ($name==='video_mask_by_prompt') $body['prompt']='A cube';
            if ($name==='video_mask_by_key_points') $body['key_points']=[['type'=>'positive','x'=>1,'y'=>1]];
            try { $native->prepare($body,$this->model('Bria/'.$name));$this->fail('Route resolution limit was exceeded.'); }
            catch (AiRequestException $e) { $this->assertSame('invalid_payload',$e->errorCode); }
        }
        $model=$this->model('Bria/video_increase_resolution');
        $this->assertSame(100000,$native->prepare(['video'=>$wide,'desired_increase'=>4],$model)->native['max_cost_micro']);
        $tall='data:video/mp4;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/ai/tall-one-second-h264.mp4')));
        $this->assertSame(100000,$native->prepare(['video'=>$tall,'desired_increase'=>2],$model)->native['max_cost_micro']);
        $this->expectException(AiRequestException::class);
        $native->prepare(['video'=>$tall,'desired_increase'=>4],$model);
    }
}
