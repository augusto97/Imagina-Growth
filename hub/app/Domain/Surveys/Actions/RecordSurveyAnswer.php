<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Sites\Models\Site;
use App\Domain\Surveys\Jobs\AttachSurveyResponse;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Illuminate\Support\Facades\DB;

/**
 * Guarda la respuesta de la página de gracias y la vincula con el pedido y el
 * contacto (api-connector.md §8.2). Si el pedido todavía no llegó al hub, la
 * vincula un job unos minutos después.
 */
final class RecordSurveyAnswer
{
    public function __construct(private readonly HubEvents $hubEvents) {}

    public function handle(Workspace $workspace, Site $site, SurveyDefinition $survey, string $externalOrderId, string $answer, ?string $other, ?string $anonymousId): SurveyResponse
    {
        $order = Order::query()->where('site_id', $site->id)->where('external_id', $externalOrderId)->first(['id', 'contact_id']);
        $contactId = $order?->contact_id;
        if ($contactId === null && $anonymousId !== null) {
            $contactId = ContactIdentity::query()->where('type', 'anonymous_id')->where('value', $anonymousId)->value('contact_id');
        }

        $response = DB::transaction(function () use ($workspace, $site, $survey, $externalOrderId, $answer, $other, $order, $contactId): SurveyResponse {
            $response = SurveyResponse::query()->create([
                'workspace_id' => $workspace->id,
                'survey_id' => $survey->id,
                'site_id' => $site->id,
                'contact_id' => $contactId,
                'order_id' => $order?->id,
                'external_order_id' => $externalOrderId,
                'answer' => $answer,
                'answers' => ['answer' => $answer, 'other' => $other],
            ]);
            if (is_string($contactId)) {
                self::fillContact($contactId, $answer);
            }

            return $response;
        });

        $this->hubEvents->emit($workspace, 'survey.answered', [
            'survey_id' => $survey->publicId(), 'kind' => 'hdyhau', 'answer' => $answer,
            'other' => $other, 'score' => null, 'order_id' => $externalOrderId,
        ], is_string($contactId) ? $contactId : null, $site);

        if ($order === null) {
            AttachSurveyResponse::dispatch($workspace->id, $response->id)->delay(now()->addMinutes(2));
        }

        return $response;
    }

    /** Llena contacts.hdyhau si estaba vacío (la primera respuesta es la que vale). */
    public static function fillContact(string $contactId, string $answer): void
    {
        Contact::query()->whereKey($contactId)->whereNull('hdyhau')->update(['hdyhau' => mb_substr($answer, 0, 120)]);
    }
}
