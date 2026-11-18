<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Workspaces\Models\Workspace;

/**
 * La encuesta «¿Cómo nos conociste?» (modulos.md M12, adelantada a la Fase 1):
 * una por espacio de trabajo, creada con opciones por defecto y editable.
 */
final class HdyhauSurvey
{
    public function for(Workspace $workspace): SurveyDefinition
    {
        /** @var SurveyDefinition|null $survey */
        $survey = SurveyDefinition::query()->where('workspace_id', $workspace->id)->where('kind', 'hdyhau')->first();

        return $survey ?? SurveyDefinition::query()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'hdyhau',
            'status' => 'active',
            'questions' => self::defaults(),
            'trigger' => ['page_type' => 'thankyou'],
        ]);
    }

    /** @return array{title: string, question: string, options: list<array{key: string, label: string}>, allow_other: bool, other_label: string, thanks: string} */
    public static function defaults(): array
    {
        $options = [
            'instagram' => __('Instagram'), 'facebook' => __('Facebook'), 'tiktok' => __('TikTok'),
            'google' => __('Buscando en Google'), 'youtube' => __('YouTube'), 'whatsapp' => __('WhatsApp'),
            'recomendacion' => __('Me lo recomendó alguien'), 'tienda_fisica' => __('En la tienda física'),
            'radio_tv' => __('Radio o televisión'), 'asistente_ia' => __('ChatGPT u otro asistente de IA'),
        ];

        return [
            'title' => __('Una pregunta rápida'),
            'question' => __('¿Cómo nos conociste?'),
            'options' => array_values(array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys($options), $options)),
            'allow_other' => true,
            'other_label' => __('Otro'),
            'thanks' => __('¡Gracias por contarnos!'),
        ];
    }
}
