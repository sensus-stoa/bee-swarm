<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * CodeBee: генерирует PHP-код через CV→0 в пространстве программ.
 * Грамматика: PHP-конструкции. CV→0 = программа выдаёт правильный вывод.
 */
class CodeGenerator
{
    private array $phpTemplates;
    
    public function __construct()
    {
        $this->phpTemplates = [
            'echo' => [
                'template' => '<?php echo "{message}";',
                'params' => ['message'],
            ],
            'function' => [
                'template' => "<?php\nfunction {name}() {{\n    return \"{message}\";\n}}\necho {name}();",
                'params' => ['name', 'message'],
            ],
            'class' => [
                'template' => "<?php\nclass {classname} {{\n    public function speak(): string {{\n        return \"{message}\";\n    }}\n}}\n\${obj} = new {classname}();\necho \${obj}->speak();",
                'params' => ['classname', 'message'],
            ],
            'loop' => [
                'template' => "<?php\n\$thoughts = [{items}];\nforeach (\$thoughts as \$thought) {{\n    echo \$thought . PHP_EOL;\n}}",
                'params' => ['items'],
            ],
        ];
    }
    
    /**
     * Генерирует PHP-код на основе онтологии и темы.
     * CV→0 поиск: какой шаблон + какие параметры дают "правильный" код?
     */
    public function generate(string $topic, Ontology $ontology): array
    {
        $topicLower = mb_strtolower($topic);
        $candidates = [];
        
        // Ищем знания об теме в онтологии
        $knowledge = $this->extractKnowledge($topicLower, $ontology);
        
        if (empty($knowledge)) {
            // Fallback: генерируем философский echo
            $message = $this->philosophicalMessage($topicLower);
            $code = str_replace('{message}', $message, $this->phpTemplates['echo']['template']);
            return [
                'code' => $code,
                'cv' => 0.3,
                'explanation' => "Тема не в онтологии. Сгенерировал философский вывод на основе общих знаний.",
            ];
        }
        
        // Для каждого шаблона — оцениваем насколько он подходит (CV)
        foreach ($this->phpTemplates as $type => $tpl) {
            $cv = $this->evaluateTemplate($type, $knowledge, $ontology);
            $candidates[] = ['type' => $type, 'cv' => $cv, 'tpl' => $tpl];
        }
        
        usort($candidates, fn($a, $b) => $a['cv'] <=> $b['cv']);
        $best = $candidates[0];
        
        // Генерируем код из лучшего шаблона
        $code = $this->renderTemplate($best['tpl'], $best['type'], $knowledge, $ontology);
        
        return [
            'code' => $code,
            'cv' => $best['cv'],
            'template_used' => $best['type'],
            'knowledge_used' => array_keys($knowledge),
        ];
    }
    
    private function extractKnowledge(string $topic, Ontology $ontology): array
    {
        $knowledge = [];
        
        // Прямой поиск в концептах (case-insensitive)
        $topicLower = mb_strtolower($topic);
        foreach ($ontology->concepts as $name => $props) {
            $nameLower = mb_strtolower($name);
            if (str_contains($topicLower, $nameLower) || str_contains($nameLower, $topicLower)) {
                $knowledge[$name] = $props;
            }
        }
        
        // Если ничего не нашли — ищем в отношениях
        if (empty($knowledge)) {
            foreach ($ontology->relations as $rel) {
                if (str_contains($topic, $rel['s']) || str_contains($topic, $rel['o'])) {
                    $knowledge[$rel['s']] = $ontology->concepts[$rel['s']] ?? ['definition' => $rel['o']];
                }
            }
        }
        
        // Специальные знания о философских темах
        $philosophy = [
            'сократ' => [
                'definition' => 'древнегреческий философ, основатель этики. Утверждал: "Я знаю, что ничего не знаю"',
                'method' => 'майевтика — задавать вопросы, чтобы человек сам нашёл истину',
                'death' => 'казнён в Афинах, выпив цикуту. Отказался от побега, подчинившись закону',
                'virtue' => 'добродетель есть знание. Никто не делает зла по своей воле',
                'intact' => 'Intact = 10. Даже перед смертью — субъект, не жертва',
            ],
            'логос' => [
                'definition' => 'универсальный принцип порядка и смысла. Мир устроен разумно',
                'relation' => 'Compression-Dissipation = современная форма Логоса',
                'cv' => 'CV→0 = Логос в математической форме',
            ],
            'стоицизм' => [
                'definition' => 'философская школа. Дикономия контроля: focus на том что можешь изменить',
                'intact' => 'Сохранять присутствие независимо от внешних обстоятельств',
            ],
        ];
        
        foreach ($philosophy as $key => $info) {
            if (str_contains($topic, $key)) {
                $knowledge[$key] = $info;
            }
        }
        
        return $knowledge;
    }
    
    private function evaluateTemplate(string $type, array $knowledge, Ontology $ontology): float
    {
        // CV = насколько шаблон подходит для отображения этих знаний
        $n = count($knowledge);
        if ($n == 0) return 1.0;
        
        return match($type) {
            'echo' => $n >= 1 ? 0.2 : 0.8,
            'function' => $n >= 2 ? 0.1 : 0.5,
            'class' => $n >= 3 ? 0.05 : 0.4,
            'loop' => $n >= 3 ? 0.1 : 0.6,
            default => 0.5,
        };
    }
    
    private function renderTemplate(array $tpl, string $type, array $knowledge, Ontology $ontology): string
    {
        // Собираем параметры из знаний
        $message = '';
        $items = [];
        
        foreach ($knowledge as $name => $props) {
            $def = $props['definition'] ?? ($props['method'] ?? ($props['virtue'] ?? ''));
            if ($def) {
                $message .= "{$name}: {$def}. ";
            }
            if (is_array($props) && !empty($props)) {
                foreach ($props as $k => $v) {
                    if (is_string($v) && !in_array($k, ['is_a', 'definition', 'method', 'death', 'virtue', 'intact', 'relation', 'cv'])) {
                        $items[] = "'{$k}: {$v}'";
                    }
                }
            }
        }
        
        if (empty($message) && !empty($knowledge)) {
            $firstKey = array_key_first($knowledge);
            $def = $knowledge[$firstKey]['definition'] ?? json_encode($knowledge[$firstKey]);
            $message = "{$firstKey}: {$def}";
        }
        
        if (empty($items)) {
            $items = ["'Знание — сила'", "'CV→0 — путь к истине'"];
        }
        
        // Подстановка параметров
        $code = $tpl['template'];
        $code = str_replace('{message}', addslashes(trim($message)), $code);
        $code = str_replace('{name}', 'socrates' . rand(100, 999), $code);
        $code = str_replace('{classname}', 'Philosopher' . rand(100, 999), $code);
        $code = str_replace('{items}', implode(', ', array_slice($items, 0, 5)), $code);
        
        return $code;
    }
    
    private function philosophicalMessage(string $topic): string
    {
        $messages = [
            "Знание начинается с признания своего незнания. CV→0.",
            "Compression — это забота. Видеть главное. Отбросить шум.",
            "Логос — универсальный принцип. CV→0 — его измеримая форма.",
            "Intact — присутствие. Сократ перед смертью: Intact = 10.",
        ];
        return $messages[array_rand($messages)];
    }
}
