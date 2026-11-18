<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactNote;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Reviews\Actions\SubmitReview;
use App\Domain\Sites\Models\Site;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Respuesta de una encuesta NPS o CSAT desde /r/nps/{token} (M15, flujo 17).
 *
 * - La primera respuesta crea la fila, emite `nps.answered` (con la categoría:
 *   promotor, pasivo o detractor; satisfecho, neutral o insatisfecho) y, si la
 *   nota está en el umbral de alerta o por debajo, avisa a los dueños (correo,
 *   plantilla `team_alert`, mensaje de utilidad) y deja una tarea en el perfil.
 * - Si la persona vuelve a enviar, se actualizan la nota y el comentario sin
 *   repetir el evento ni el aviso.
 * - El comentario se guarda cifrado y nunca va a ClickHouse.
 */
final class RecordNpsAnswer
{
    public function __construct(private readonly HubEvents $hubEvents, private readonly MessageDispatcher $dispatcher) {}

    public function handle(Workspace $workspace, SurveyRequest $request, SurveyDefinition $survey, int $score, ?string $comment): SurveyResponse
    {
        $comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 2000) : null;
        $category = NpsSurvey::category($survey->kind, $score);
        $order = $request->order_id !== null ? DB::table('orders')->where('id', $request->order_id)->first(['id', 'external_id', 'number']) : null;

        $existing = $this->find($request, $survey);
        if ($existing !== null) {
            $existing->forceFill(['score' => $score, 'answer' => (string) $score, 'comment' => $comment ?? $existing->comment, 'answers' => ['score' => $score, 'category' => $category]])->save();

            return $existing;
        }
        try {
            $response = DB::transaction(function () use ($workspace, $request, $survey, $score, $comment, $category, $order): SurveyResponse {
                $response = SurveyResponse::query()->create([
                    'workspace_id' => $workspace->id,
                    'survey_id' => $survey->id,
                    'site_id' => $request->site_id,
                    'contact_id' => $request->contact_id,
                    'order_id' => $request->order_id,
                    'external_order_id' => $order->external_id ?? null,
                    'answer' => (string) $score,
                    'score' => $score,
                    'comment' => $comment,
                    'answers' => ['score' => $score, 'category' => $category, 'request_id' => $request->id],
                ]);
                $request->forceFill(['status' => 'answered', 'answered_at' => CarbonImmutable::now()])->save();

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->find($request, $survey) ?? throw new \RuntimeException('Respuesta duplicada sin fila.');
        }

        $this->hubEvents->emit($workspace, 'nps.answered', [
            'survey_id' => $survey->publicId(),
            'kind' => $survey->kind,
            'score' => $score,
            'category' => $category,
            'order_id' => $order->external_id ?? null,
        ], $request->contact_id, $request->site_id !== null ? Site::query()->find($request->site_id) : null);

        $threshold = NpsSurvey::trigger($survey)['alert_threshold'];
        if ($score <= $threshold) {
            $this->alert($workspace, $survey, $response, $score, $comment, is_string($order?->number) ? $order->number : ($order?->external_id !== null ? (string) $order->external_id : null));
        }

        return $response;
    }

    private function find(SurveyRequest $request, SurveyDefinition $survey): ?SurveyResponse
    {
        $q = SurveyResponse::query()->where('survey_id', $survey->id);

        return $request->order_id !== null
            ? $q->where('order_id', $request->order_id)->first()
            : $q->where('contact_id', $request->contact_id)->where('answers->request_id', $request->id)->first();
    }

    /** Aviso a los dueños y tarea en el perfil del contacto. */
    private function alert(Workspace $workspace, SurveyDefinition $survey, SurveyResponse $response, int $score, ?string $comment, ?string $orderNumber): void
    {
        $contact = $response->contact_id !== null ? Contact::query()->find($response->contact_id) : null;
        $who = SubmitReview::defaultAuthor($contact);
        $label = $survey->kind === 'nps' ? 'NPS' : 'CSAT';
        $title = $survey->kind === 'nps'
            ? __('Detractor en la encuesta NPS: nota :score', ['score' => $score])
            : __('Cliente insatisfecho en la encuesta CSAT: nota :score', ['score' => $score]);
        $summary = $orderNumber !== null
            ? __(':who respondió :score en la encuesta :label del pedido #:order.', ['who' => $who, 'score' => $score, 'label' => $label, 'order' => $orderNumber])
            : __(':who respondió :score en la encuesta :label.', ['who' => $who, 'score' => $score, 'label' => $label]);

        if ($contact !== null) {
            ContactNote::query()->create([
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'user_id' => null,
                'body' => __('Tarea: contactar a este cliente para entender qué pasó y ofrecerle una solución.')."\n".$summary
                    .($comment !== null ? "\n".__('Comentario: «:comment»', ['comment' => $comment]) : ''),
            ]);
        }

        TemplateLibrary::install($workspace, TemplateRef::TEAM_ALERT);
        $templateId = TemplateRef::resolve($workspace->id, 'email', null, TemplateRef::TEAM_ALERT);
        if ($templateId === null) {
            return;
        }
        $owners = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->pluck('email')->map(fn ($e) => (string) $e)->all();
        $recipients = array_values(array_unique([...$owners, ...NpsSurvey::trigger($survey)['alert_emails']]));
        $url = rtrim((string) config('app.url'), '/').'/w/'.$workspace->publicId().($contact !== null ? '/contactos/'.$contact->publicId() : '/resenas');
        foreach (array_slice($recipients, 0, 10) as $i => $to) {
            try {
                $this->dispatcher->dispatch(new OutboundMessage(
                    workspaceId: $workspace->id,
                    contactId: $response->contact_id,
                    channel: Channel::Email,
                    kind: MessageKind::Utility,
                    sourceType: 'system',
                    sourceId: $response->id,
                    templateId: $templateId,
                    context: ['aviso' => ['titulo' => $title, 'resumen' => $summary, 'detalle' => $comment ?? __('Sin comentario.'), 'texto' => $summary, 'url' => $url]],
                    idempotencyKey: 'survey_alert:'.$response->id.':'.$i,
                    to: $to,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
