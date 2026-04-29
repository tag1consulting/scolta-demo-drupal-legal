<?php

namespace Drupal\complianceiq_import\Commands;

use Drupal\complianceiq_import\ComplianceImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commands for ComplianceIQ content import and generation.
 */
final class ImportCommands extends DrushCommands {

  public function __construct(
    private readonly ComplianceImporter $importer,
    private readonly ClientInterface $httpClient,
  ) {
    parent::__construct();
  }

  /**
   * Import regulatory text from public government sources.
   */
  #[CLI\Command(name: 'complianceiq:import-regulations', aliases: ['ciq-regs'])]
  #[CLI\Usage(name: 'drush complianceiq:import-regulations', description: 'Import all regulatory frameworks')]
  public function importRegulations(): void {
    $this->importGdpr();
    $this->importHipaa();
    $this->importCcpa();
    $this->importSox();
    $this->importFerpa();
    $this->importAda();
    $this->importFedramp();
    $this->importPciDss();
    $this->output()->writeln('<info>Regulation import complete.</info>');
  }

  /**
   * Import enforcement cases from public databases.
   */
  #[CLI\Command(name: 'complianceiq:import-cases', aliases: ['ciq-cases'])]
  public function importCases(): void {
    $this->importIcoCases();
    $this->importHhsOcrCases();
    $this->importFtcCases();
    $this->output()->writeln('<info>Case import complete.</info>');
  }

  /**
   * Generate guidance articles using Claude API.
   */
  #[CLI\Command(name: 'complianceiq:generate-guidance', aliases: ['ciq-guidance'])]
  #[CLI\Option(name: 'batch', description: 'Batch size per run')]
  public function generateGuidance(array $options = ['batch' => 30]): void {
    $api_key = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_API_KEY');
    if (!$api_key) {
      $this->io()->error('ANTHROPIC_API_KEY not set.');
      return;
    }
    $topics = $this->getGuidanceTopics();
    $total = count($topics);
    $batch = (int) ($options['batch'] ?? 30);
    $this->io()->writeln("<info>Generating {$total} guidance articles in batches of {$batch}...</info>");
    foreach (array_chunk($topics, $batch) as $chunk_idx => $chunk) {
      foreach ($chunk as $idx => $topic) {
        $global = ($chunk_idx * $batch) + $idx + 1;
        $this->io()->writeln("  [{$global}/{$total}] {$topic['title']}");
        $content = $this->callClaude($api_key, $this->buildGuidancePrompt($topic));
        if ($content) {
          $this->importer->createGuidanceArticle([
            'title' => $topic['title'],
            'body' => $content['body'] ?? '',
            'key_takeaways' => $content['takeaways'] ?? '',
            'regulations' => $topic['regulations'],
            'audience' => $topic['audience'],
            'difficulty' => $topic['difficulty'],
            'last_reviewed' => date('Y-m-d'),
          ]);
        }
      }
    }
    $this->io()->writeln('<info>Guidance generation complete.</info>');
  }

  /**
   * Generate compliance checklists using Claude API.
   */
  #[CLI\Command(name: 'complianceiq:generate-checklists', aliases: ['ciq-checklists'])]
  public function generateChecklists(): void {
    $api_key = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_API_KEY');
    if (!$api_key) {
      $this->io()->error('ANTHROPIC_API_KEY not set.');
      return;
    }
    $topics = $this->getChecklistTopics();
    $total = count($topics);
    foreach ($topics as $idx => $topic) {
      $n = $idx + 1;
      $this->io()->writeln("  [{$n}/{$total}] {$topic['title']}");
      $content = $this->callClaude($api_key, $this->buildChecklistPrompt($topic));
      if ($content) {
        $this->importer->createChecklist([
          'title' => $topic['title'],
          'body' => $content['intro'] ?? '',
          'checklist_items' => $content['items'] ?? '',
          'regulation' => $topic['regulation'],
          'audience' => $topic['audience'],
        ]);
      }
    }
    $this->io()->writeln('<info>Checklist generation complete.</info>');
  }

  /**
   * Generate cross-regulation comparison pages using Claude API.
   */
  #[CLI\Command(name: 'complianceiq:generate-comparisons', aliases: ['ciq-compare'])]
  public function generateComparisons(): void {
    $api_key = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_API_KEY');
    if (!$api_key) {
      $this->io()->error('ANTHROPIC_API_KEY not set.');
      return;
    }
    $topics = $this->getComparisonTopics();
    $total = count($topics);
    foreach ($topics as $idx => $topic) {
      $n = $idx + 1;
      $this->io()->writeln("  [{$n}/{$total}] {$topic['title']}");
      $content = $this->callClaude($api_key, $this->buildComparisonPrompt($topic));
      if ($content) {
        $this->importer->createComparison([
          'title' => $topic['title'],
          'body' => $content['body'] ?? '',
          'comparison_table' => $content['table'] ?? '',
          'key_differences' => $content['differences'] ?? '',
          'regulations' => $topic['regulations'],
        ]);
      }
    }
    $this->io()->writeln('<info>Comparison generation complete.</info>');
  }

  /**
   * Generate glossary terms using Claude API.
   */
  #[CLI\Command(name: 'complianceiq:generate-glossary', aliases: ['ciq-glossary'])]
  public function generateGlossary(): void {
    $api_key = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_API_KEY');
    if (!$api_key) {
      $this->io()->error('ANTHROPIC_API_KEY not set.');
      return;
    }
    $terms = $this->getGlossaryTerms();
    $total = count($terms);
    foreach ($terms as $idx => $term) {
      $n = $idx + 1;
      $this->io()->writeln("  [{$n}/{$total}] {$term['title']}");
      $content = $this->callClaude($api_key, $this->buildGlossaryPrompt($term));
      if ($content) {
        $this->importer->createGlossaryTerm([
          'title' => $term['title'],
          'body' => $content['definition'] ?? '',
          'regulation_definitions' => $content['reg_definitions'] ?? '',
          'regulations' => $term['regulations'],
        ]);
      }
    }
    $this->io()->writeln('<info>Glossary generation complete.</info>');
  }

  /**
   * Build entity cross-references between all content types.
   */
  #[CLI\Command(name: 'complianceiq:cross-reference', aliases: ['ciq-xref'])]
  public function crossReference(): void {
    $this->io()->writeln('<info>Building cross-references...</info>');
    $this->importer->buildCrossReferences();
    $this->io()->writeln('<info>Cross-reference complete.</info>');
  }

  // ---------------------------------------------------------------------------
  // Regulation importers
  // ---------------------------------------------------------------------------

  private function importGdpr(): void {
    $this->io()->writeln('<info>Importing GDPR...</info>');
    $articles = $this->getGdprArticles();
    $total = count($articles);
    foreach ($articles as $idx => $article) {
      $n = $idx + 1;
      $this->io()->writeln("  GDPR [{$n}/{$total}] {$article['title']}");
      $this->importer->createRegulationSection(array_merge($article, [
        'regulation' => 'GDPR',
        'jurisdiction' => 'European Union',
        'enforcement_body' => 'ICO (UK) / National DPAs',
        'effective_date' => '2018-05-25',
      ]));
    }
  }

  private function importHipaa(): void {
    $this->io()->writeln('<info>Importing HIPAA...</info>');
    $sections = $this->getHipaaRules();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  HIPAA [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'HIPAA',
        'jurisdiction' => 'US Federal',
        'enforcement_body' => 'HHS OCR (US)',
        'effective_date' => '2013-03-26',
      ]));
    }
  }

  private function importCcpa(): void {
    $this->io()->writeln('<info>Importing CCPA/CPRA...</info>');
    $sections = $this->getCcpaSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  CCPA [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'CCPA/CPRA',
        'jurisdiction' => 'California',
        'enforcement_body' => 'California AG',
        'effective_date' => '2020-01-01',
      ]));
    }
  }

  private function importSox(): void {
    $this->io()->writeln('<info>Importing SOX...</info>');
    $sections = $this->getSoxSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  SOX [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'SOX',
        'jurisdiction' => 'US Federal',
        'enforcement_body' => 'SEC',
        'effective_date' => '2002-07-30',
      ]));
    }
  }

  private function importFerpa(): void {
    $this->io()->writeln('<info>Importing FERPA...</info>');
    $sections = $this->getFerpaSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  FERPA [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'FERPA',
        'jurisdiction' => 'US Federal',
        'enforcement_body' => 'US Dept. of Education',
        'effective_date' => '1974-08-21',
      ]));
    }
  }

  private function importAda(): void {
    $this->io()->writeln('<info>Importing ADA/Section 508...</info>');
    $sections = $this->getAdaSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  ADA [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'ADA/Section 508',
        'jurisdiction' => 'US Federal',
        'enforcement_body' => 'DOJ / Access Board',
        'effective_date' => '2018-01-18',
      ]));
    }
  }

  private function importFedramp(): void {
    $this->io()->writeln('<info>Importing FedRAMP...</info>');
    $sections = $this->getFedrampSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  FedRAMP [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'FedRAMP',
        'jurisdiction' => 'US Federal',
        'enforcement_body' => 'FedRAMP PMO / OMB',
        'effective_date' => '2022-11-22',
      ]));
    }
  }

  private function importPciDss(): void {
    $this->io()->writeln('<info>Importing PCI-DSS...</info>');
    $sections = $this->getPciDssSections();
    $total = count($sections);
    foreach ($sections as $idx => $section) {
      $n = $idx + 1;
      $this->io()->writeln("  PCI-DSS [{$n}/{$total}] {$section['title']}");
      $this->importer->createRegulationSection(array_merge($section, [
        'regulation' => 'PCI-DSS',
        'jurisdiction' => 'International',
        'enforcement_body' => 'PCI SSC',
        'effective_date' => '2022-03-31',
      ]));
    }
  }

  // ---------------------------------------------------------------------------
  // Case importers
  // ---------------------------------------------------------------------------

  private function importIcoCases(): void {
    $this->io()->writeln('<info>Importing ICO enforcement cases...</info>');
    $cases = $this->getIcoCaseData();
    $total = count($cases);
    foreach ($cases as $idx => $case) {
      $n = $idx + 1;
      $this->io()->writeln("  ICO [{$n}/{$total}] {$case['title']}");
      $this->importer->createEnforcementCase($case);
    }
  }

  private function importHhsOcrCases(): void {
    $this->io()->writeln('<info>Importing HHS OCR enforcement cases...</info>');
    $cases = $this->getHhsOcrCaseData();
    $total = count($cases);
    foreach ($cases as $idx => $case) {
      $n = $idx + 1;
      $this->io()->writeln("  HHS OCR [{$n}/{$total}] {$case['title']}");
      $this->importer->createEnforcementCase($case);
    }
  }

  private function importFtcCases(): void {
    $this->io()->writeln('<info>Importing FTC enforcement cases...</info>');
    $cases = $this->getFtcCaseData();
    $total = count($cases);
    foreach ($cases as $idx => $case) {
      $n = $idx + 1;
      $this->io()->writeln("  FTC [{$n}/{$total}] {$case['title']}");
      $this->importer->createEnforcementCase($case);
    }
  }

  // ---------------------------------------------------------------------------
  // Claude API
  // ---------------------------------------------------------------------------

  private function callClaude(string $api_key, string $prompt): ?array {
    try {
      $response = $this->httpClient->post('https://api.anthropic.com/v1/messages', [
        'headers' => [
          'x-api-key' => $api_key,
          'anthropic-version' => '2023-06-01',
          'content-type' => 'application/json',
        ],
        'json' => [
          'model' => 'claude-haiku-4-5-20251001',
          'max_tokens' => 2048,
          'messages' => [['role' => 'user', 'content' => $prompt]],
        ],
        'timeout' => 60,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      $text = $data['content'][0]['text'] ?? '';
      return $this->parseClaudeResponse($text);
    }
    catch (\Throwable $e) {
      $this->logger()->warning('Claude API error: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  private function parseClaudeResponse(string $text): array {
    if (preg_match('/```json\s*(\{.*?\})\s*```/s', $text, $m)) {
      $decoded = json_decode($m[1], TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }
    return [
      'body' => $text,
      'takeaways' => '',
      'intro' => '',
      'items' => '',
      'table' => '',
      'differences' => '',
      'definition' => $text,
      'reg_definitions' => '',
    ];
  }

  // ---------------------------------------------------------------------------
  // Prompt builders
  // ---------------------------------------------------------------------------

  private function buildGuidancePrompt(array $topic): string {
    $regs = implode(', ', $topic['regulations']);
    return <<<PROMPT
You are a compliance expert writing plain-language guidance for enterprise compliance professionals.

Write a practical guidance article titled "{$topic['title']}" covering {$regs}.
Audience: {$topic['audience']}. Difficulty: {$topic['difficulty']}.

Requirements:
- Cite at least 3 specific regulation sections (e.g., "GDPR Art. 17", "HIPAA §164.524")
- 600-900 words
- Practical, actionable tone

Respond ONLY with this JSON block (no other text):
```json
{
  "body": "<full HTML article body with <p> and <h3> tags>",
  "takeaways": "<HTML <ul> list of 3-5 key takeaways>"
}
```
PROMPT;
  }

  private function buildChecklistPrompt(array $topic): string {
    return <<<PROMPT
You are a compliance expert creating an actionable compliance checklist.

Create a checklist titled "{$topic['title']}" for {$topic['regulation']}.
Audience: {$topic['audience']}.

Requirements:
- 12-20 checklist items
- Each item references a specific regulation section
- Items are concrete and verifiable

Respond ONLY with this JSON block:
```json
{
  "intro": "<one paragraph HTML intro>",
  "items": "<HTML <ul> list of checklist items>"
}
```
PROMPT;
  }

  private function buildComparisonPrompt(array $topic): string {
    $regs = implode(' vs. ', $topic['regulations']);
    return <<<PROMPT
You are a compliance expert writing a cross-regulation comparison for enterprise legal and IT teams.

Create a comparison: "{$topic['title']}"
Regulations: {$regs}

Requirements:
- HTML table comparing: Scope, Key Rights/Obligations, Notification Timelines, Penalties, Enforcement
- 3-5 paragraph analysis
- Cite specific sections

Respond ONLY with this JSON block:
```json
{
  "body": "<HTML analysis paragraphs>",
  "table": "<HTML table with class='ciq-table'>",
  "differences": "<HTML <ul> of key differences>"
}
```
PROMPT;
  }

  private function buildGlossaryPrompt(array $term): string {
    $regs = implode(', ', $term['regulations']);
    return <<<PROMPT
You are a compliance expert writing a glossary for enterprise compliance professionals.

Define the term: "{$term['title']}"
Relevant regulations: {$regs}

Requirements:
- General definition (2-3 sentences)
- Regulation-specific definitions where the term differs
- Cite section numbers

Respond ONLY with this JSON block:
```json
{
  "definition": "<HTML general definition>",
  "reg_definitions": "<HTML list of regulation-specific definitions>"
}
```
PROMPT;
  }

  // ---------------------------------------------------------------------------
  // Regulatory content data — all 8 frameworks
  // ---------------------------------------------------------------------------

  private function getGdprArticles(): array {
    return [
      ['title' => 'Article 1 — Subject-matter and objectives', 'section_number' => 'Art. 1', 'chapter' => 'Chapter I', 'severity' => 'low', 'plain_summary' => 'Establishes the GDPR\'s purpose: protecting personal data rights while allowing free data movement across the EU.', 'body' => '<p>This Regulation lays down rules relating to the protection of natural persons with regard to the processing of personal data and rules relating to the free movement of personal data.</p><p>This Regulation protects fundamental rights and freedoms of natural persons and in particular their right to the protection of personal data.</p><p>The free movement of personal data within the Union shall be neither restricted nor prohibited for reasons connected with the protection of natural persons with regard to the processing of personal data.</p>'],
      ['title' => 'Article 2 — Material scope', 'section_number' => 'Art. 2', 'chapter' => 'Chapter I', 'severity' => 'medium', 'plain_summary' => 'GDPR applies to automated processing of personal data and manual filing systems. Excludes purely personal/household activities.', 'body' => '<p>This Regulation applies to the processing of personal data wholly or partly by automated means and to the processing other than by automated means of personal data which form part of a filing system or are intended to form part of a filing system.</p><p>This Regulation does not apply to the processing of personal data in the course of an activity which falls outside the scope of Union law, by a natural person in the course of a purely personal or household activity.</p>'],
      ['title' => 'Article 3 — Territorial scope', 'section_number' => 'Art. 3', 'chapter' => 'Chapter I', 'severity' => 'high', 'plain_summary' => 'GDPR applies to any organization processing EU residents\' data — even companies outside the EU.', 'body' => '<p>This Regulation applies to the processing of personal data in the context of the activities of an establishment of a controller or a processor in the Union, regardless of whether the processing takes place in the Union or not.</p><p>This Regulation applies to the processing of personal data of data subjects who are in the Union by a controller or processor not established in the Union, where the processing activities are related to: the offering of goods or services to such data subjects in the Union; or the monitoring of their behaviour as far as their behaviour takes place within the Union.</p>'],
      ['title' => 'Article 4 — Definitions', 'section_number' => 'Art. 4', 'chapter' => 'Chapter I', 'severity' => 'medium', 'plain_summary' => 'Core definitions. "Personal data" is broad — any information that can identify a person. "Controller" decides the why/how; "processor" acts on controller\'s behalf.', 'body' => '<p>(1) "personal data" means any information relating to an identified or identifiable natural person; an identifiable natural person is one who can be identified, directly or indirectly, in particular by reference to an identifier such as a name, an identification number, location data, an online identifier.</p><p>(2) "processing" means any operation or set of operations which is performed on personal data, whether or not by automated means, such as collection, recording, organisation, structuring, storage, adaptation, retrieval, consultation, use, disclosure by transmission, dissemination, restriction, erasure or destruction.</p><p>(7) "controller" means the natural or legal person which, alone or jointly with others, determines the purposes and means of the processing of personal data.</p>'],
      ['title' => 'Article 5 — Principles relating to processing of personal data', 'section_number' => 'Art. 5', 'chapter' => 'Chapter II', 'severity' => 'critical', 'plain_summary' => 'The six core GDPR principles: lawfulness/fairness/transparency, purpose limitation, data minimization, accuracy, storage limitation, and integrity/confidentiality.', 'body' => '<p>Personal data shall be:</p><p>(a) processed lawfully, fairly and in a transparent manner in relation to the data subject ("lawfulness, fairness and transparency");</p><p>(b) collected for specified, explicit and legitimate purposes and not further processed in a manner that is incompatible with those purposes ("purpose limitation");</p><p>(c) adequate, relevant and limited to what is necessary in relation to the purposes for which they are processed ("data minimisation");</p><p>(d) accurate and, where necessary, kept up to date ("accuracy");</p><p>(e) kept in a form which permits identification of data subjects for no longer than is necessary ("storage limitation");</p><p>(f) processed in a manner that ensures appropriate security of the personal data ("integrity and confidentiality").</p>'],
      ['title' => 'Article 6 — Lawfulness of processing', 'section_number' => 'Art. 6', 'chapter' => 'Chapter II', 'severity' => 'critical', 'plain_summary' => 'Six legal bases for processing: consent, contract, legal obligation, vital interests, public task, legitimate interests. Must identify your basis BEFORE processing.', 'body' => '<p>Processing shall be lawful only if and to the extent that at least one of the following applies:</p><p>(a) the data subject has given consent to the processing of his or her personal data for one or more specific purposes;</p><p>(b) processing is necessary for the performance of a contract to which the data subject is party;</p><p>(c) processing is necessary for compliance with a legal obligation to which the controller is subject;</p><p>(d) processing is necessary in order to protect the vital interests of the data subject;</p><p>(e) processing is necessary for the performance of a task carried out in the public interest;</p><p>(f) processing is necessary for the purposes of the legitimate interests pursued by the controller or by a third party, except where such interests are overridden by the interests or fundamental rights of the data subject.</p>'],
      ['title' => 'Article 7 — Conditions for consent', 'section_number' => 'Art. 7', 'chapter' => 'Chapter II', 'severity' => 'critical', 'plain_summary' => 'Consent must be freely given, specific, informed, and unambiguous. Pre-ticked boxes don\'t count. Withdrawing consent must be as easy as giving it.', 'body' => '<p>Where processing is based on consent, the controller shall be able to demonstrate that the data subject has consented to processing of his or her personal data.</p><p>The data subject shall have the right to withdraw his or her consent at any time. The withdrawal of consent shall not affect the lawfulness of processing based on consent before its withdrawal. It shall be as easy to withdraw consent as to give it.</p><p>When assessing whether consent is freely given, utmost account shall be taken of whether the performance of a contract is conditional on consent to the processing of personal data that is not necessary for the performance of that contract.</p>'],
      ['title' => 'Article 12 — Transparent information, communication and modalities', 'section_number' => 'Art. 12', 'chapter' => 'Chapter III', 'severity' => 'high', 'plain_summary' => 'Controllers must respond to data subject rights requests within 1 month. All communications in clear, plain language.', 'body' => '<p>The controller shall take appropriate measures to provide any information referred to in Articles 13 and 14 to the data subject in a concise, transparent, intelligible and easily accessible form, using clear and plain language.</p><p>The controller shall provide information on action taken on a request under Articles 15 to 22 to the data subject without undue delay and in any event within one month of receipt of the request. That period may be extended by two further months where necessary, taking into account the complexity and number of the requests.</p>'],
      ['title' => 'Article 13 — Information to be provided where personal data are collected from the data subject', 'section_number' => 'Art. 13', 'chapter' => 'Chapter III', 'severity' => 'high', 'plain_summary' => 'Privacy notice must disclose: who you are, why collecting, legal basis, who you share with, international transfers. Must be provided AT THE TIME of collection.', 'body' => '<p>Where personal data relating to a data subject are collected from the data subject, the controller shall, at the time when personal data are obtained, provide the data subject with all of the following information: the identity and the contact details of the controller; the purposes of the processing and the legal basis for the processing; the recipients or categories of recipients of the personal data; where applicable, the fact that the controller intends to transfer personal data to a third country.</p>'],
      ['title' => 'Article 15 — Right of access by the data subject', 'section_number' => 'Art. 15', 'chapter' => 'Chapter III', 'severity' => 'high', 'plain_summary' => 'Individuals can request a copy of all personal data you hold about them. First copy must be free. 1 month to respond (extendable to 3 months for complex requests).', 'body' => '<p>The data subject shall have the right to obtain from the controller confirmation as to whether or not personal data concerning him or her are being processed, and, where that is the case, access to the personal data.</p><p>The controller shall provide a copy of the personal data undergoing processing. For any further copies requested by the data subject, the controller may charge a reasonable fee based on administrative costs. Responses must be provided within one month of receipt of the request.</p>'],
      ['title' => 'Article 17 — Right to erasure ("right to be forgotten")', 'section_number' => 'Art. 17', 'chapter' => 'Chapter III', 'severity' => 'high', 'plain_summary' => 'Individuals can demand deletion in 6 circumstances: data no longer needed, consent withdrawn, unlawful processing, legal obligation, or collected from a child.', 'body' => '<p>The data subject shall have the right to obtain from the controller the erasure of personal data concerning him or her without undue delay where one of the following grounds applies:</p><p>(a) the personal data are no longer necessary in relation to the purposes for which they were collected;</p><p>(b) the data subject withdraws consent and there is no other legal ground for the processing;</p><p>(c) the data subject objects to the processing pursuant to Article 21(1) and there are no overriding legitimate grounds;</p><p>(d) the personal data have been unlawfully processed;</p><p>(e) the personal data have to be erased for compliance with a legal obligation.</p>'],
      ['title' => 'Article 25 — Data protection by design and by default', 'section_number' => 'Art. 25', 'chapter' => 'Chapter IV', 'severity' => 'high', 'plain_summary' => 'Privacy must be built into systems from the start and default settings must minimize data collection. Cannot be bolted on afterward.', 'body' => '<p>The controller shall, both at the time of the determination of the means for processing and at the time of the processing itself, implement appropriate technical and organisational measures, such as pseudonymisation, which are designed to implement data-protection principles, such as data minimisation, in an effective manner.</p><p>The controller shall implement appropriate technical and organisational measures for ensuring that, by default, only personal data which are necessary for each specific purpose of the processing are processed.</p>'],
      ['title' => 'Article 28 — Processor', 'section_number' => 'Art. 28', 'chapter' => 'Chapter IV', 'severity' => 'high', 'plain_summary' => 'Every vendor who processes personal data on your behalf needs a Data Processing Agreement (DPA). You\'re responsible for your processors\' compliance.', 'body' => '<p>Where processing is to be carried out on behalf of a controller, the controller shall use only processors providing sufficient guarantees to implement appropriate technical and organisational measures in such a manner that processing will meet the requirements of this Regulation.</p><p>Processing by a processor shall be governed by a contract that sets out the subject-matter and duration of the processing, the nature and purpose of the processing, the type of personal data and categories of data subjects and the obligations and rights of the controller.</p>'],
      ['title' => 'Article 30 — Records of processing activities', 'section_number' => 'Art. 30', 'chapter' => 'Chapter IV', 'severity' => 'medium', 'plain_summary' => 'Must maintain a written record (ROPA) of all data processing activities. Regulators will ask for it. Required for organizations with 250+ employees.', 'body' => '<p>Each controller shall maintain a record of processing activities under its responsibility. That record shall contain all of the following information: the name and contact details of the controller; the purposes of the processing; a description of the categories of data subjects and of the categories of personal data; the categories of recipients to whom the personal data have been or will be disclosed; where applicable, transfers of personal data to a third country.</p>'],
      ['title' => 'Article 32 — Security of processing', 'section_number' => 'Art. 32', 'chapter' => 'Chapter IV', 'severity' => 'critical', 'plain_summary' => 'Appropriate security measures required including encryption, pseudonymization, resilience, backup/recovery, and regular security testing. "Appropriate" is risk-based.', 'body' => '<p>The controller and the processor shall implement appropriate technical and organisational measures to ensure a level of security appropriate to the risk, including as appropriate:</p><p>(a) the pseudonymisation and encryption of personal data;</p><p>(b) the ability to ensure the ongoing confidentiality, integrity, availability and resilience of processing systems and services;</p><p>(c) the ability to restore the availability and access to personal data in a timely manner in the event of a physical or technical incident;</p><p>(d) a process for regularly testing, assessing and evaluating the effectiveness of technical and organisational security measures.</p>'],
      ['title' => 'Article 33 — Notification of a personal data breach to the supervisory authority', 'section_number' => 'Art. 33', 'chapter' => 'Chapter IV', 'severity' => 'critical', 'plain_summary' => '72-hour breach notification to the supervisory authority. Clock starts when you BECOME AWARE. Report what you know and follow up with details.', 'body' => '<p>In the case of a personal data breach, the controller shall without undue delay and, where feasible, not later than 72 hours after having become aware of it, notify the personal data breach to the supervisory authority, unless the personal data breach is unlikely to result in a risk to the rights and freedoms of natural persons.</p><p>The notification shall describe the nature of the breach including categories and approximate number of data subjects concerned; communicate the name and contact details of the data protection officer; describe the likely consequences of the breach; describe the measures taken or proposed to address the breach.</p>'],
      ['title' => 'Article 34 — Communication of a personal data breach to the data subject', 'section_number' => 'Art. 34', 'chapter' => 'Chapter IV', 'severity' => 'critical', 'plain_summary' => 'If breach poses HIGH RISK to individuals, you must notify affected people directly. Encryption is a safe harbor — if data is encrypted and key is safe, individual notification may not be required.', 'body' => '<p>When the personal data breach is likely to result in a high risk to the rights and freedoms of natural persons, the controller shall communicate the personal data breach to the data subject without undue delay.</p><p>The communication shall describe in clear and plain language the nature of the personal data breach and contain: the contact details of the data protection officer; a description of the likely consequences of the breach; a description of the measures taken to address the breach.</p>'],
      ['title' => 'Article 35 — Data protection impact assessment', 'section_number' => 'Art. 35', 'chapter' => 'Chapter IV', 'severity' => 'high', 'plain_summary' => 'DPIA required before starting high-risk processing: large-scale profiling, processing sensitive data at scale, or systematic public monitoring. Must be done BEFORE you start.', 'body' => '<p>Where a type of processing is likely to result in a high risk to the rights and freedoms of natural persons, the controller shall, prior to the processing, carry out an assessment of the impact of the envisaged processing operations on the protection of personal data.</p><p>A data protection impact assessment shall in particular be required in the case of: a systematic and extensive evaluation of personal aspects based on automated processing including profiling; processing on a large scale of special categories of data; or a systematic monitoring of a publicly accessible area on a large scale.</p>'],
      ['title' => 'Article 37 — Designation of the data protection officer', 'section_number' => 'Art. 37', 'chapter' => 'Chapter IV', 'severity' => 'high', 'plain_summary' => 'DPO mandatory for: public authorities, organizations doing large-scale systematic monitoring, and those processing large-scale sensitive data. DPO must be independent.', 'body' => '<p>The controller and the processor shall designate a data protection officer in any case where: (a) the processing is carried out by a public authority or body; (b) the core activities consist of processing operations which require regular and systematic monitoring of data subjects on a large scale; or (c) the core activities consist of processing on a large scale of special categories of data.</p>'],
      ['title' => 'Article 44 — General principle for transfers', 'section_number' => 'Art. 44', 'chapter' => 'Chapter V', 'severity' => 'critical', 'plain_summary' => 'Cannot simply send EU personal data outside the EU/EEA. Need a lawful transfer mechanism: adequacy decision, SCCs, Binding Corporate Rules, or specific derogations.', 'body' => '<p>Any transfer of personal data to a third country or to an international organisation shall take place only if the conditions laid down in this Chapter are complied with by the controller and processor, including for onward transfers. All provisions in this Chapter shall be applied in order to ensure that the level of protection of natural persons guaranteed by this Regulation is not undermined.</p>'],
      ['title' => 'Article 46 — Transfers subject to appropriate safeguards', 'section_number' => 'Art. 46', 'chapter' => 'Chapter V', 'severity' => 'critical', 'plain_summary' => 'Standard Contractual Clauses (SCCs) are the most common mechanism for EU-to-US transfers. Post-Schrems II, a Transfer Impact Assessment (TIA) is also required.', 'body' => '<p>In the absence of an adequacy decision, a controller or processor may transfer personal data to a third country only if the controller or processor has provided appropriate safeguards.</p><p>The appropriate safeguards may be provided for by: legally binding instruments between public authorities; binding corporate rules; standard data protection clauses adopted by the Commission; an approved code of conduct; or an approved certification mechanism.</p>'],
      ['title' => 'Article 83 — General conditions for imposing administrative fines', 'section_number' => 'Art. 83', 'chapter' => 'Chapter VIII', 'severity' => 'critical', 'plain_summary' => 'Two-tier fines: up to €10M/2% turnover for administrative violations; up to €20M/4% turnover for fundamental violations (consent, data subject rights, transfers).', 'body' => '<p>Infringements relating to basic principles for processing, conditions for consent, data subjects\' rights, and transfers of personal data to a third country shall be subject to administrative fines up to €20,000,000, or in the case of an undertaking, up to 4% of the total worldwide annual turnover of the preceding financial year, whichever is higher.</p><p>Infringements of other provisions shall be subject to administrative fines up to €10,000,000, or in the case of an undertaking, up to 2% of the total worldwide annual turnover of the preceding financial year, whichever is higher.</p>'],
    ];
  }

  private function getHipaaRules(): array {
    return [
      ['title' => 'Privacy Rule — Permitted Uses and Disclosures', 'section_number' => '§164.502', 'chapter' => 'Privacy Rule', 'severity' => 'critical', 'plain_summary' => 'PHI can only be used for treatment, payment, healthcare operations, or with valid patient authorization.', 'body' => '<p>A covered entity may not use or disclose protected health information, except as permitted or required by this subpart. A covered entity is permitted to use or disclose protected health information for treatment, payment, or health care operations as permitted by and in compliance with §164.506, or pursuant to a valid authorization under §164.508.</p>'],
      ['title' => 'Privacy Rule — Minimum Necessary Standard', 'section_number' => '§164.502(b)', 'chapter' => 'Privacy Rule', 'severity' => 'high', 'plain_summary' => 'Only access the PHI you actually need. Employees should not have access to more patient data than their role requires.', 'body' => '<p>A covered entity must make reasonable efforts to limit protected health information to the minimum necessary to accomplish the intended purpose of the use, disclosure, or request. This standard applies to all workforce members accessing PHI and to all requests for PHI disclosure to external parties.</p>'],
      ['title' => 'Privacy Rule — Notice of Privacy Practices', 'section_number' => '§164.520', 'chapter' => 'Privacy Rule', 'severity' => 'high', 'plain_summary' => 'Every covered entity must have a Notice of Privacy Practices (NPP) and provide it to patients at first service. Patients must acknowledge receipt.', 'body' => '<p>A covered entity must provide a notice of its privacy practices. The notice must describe the uses and disclosures of protected health information the covered entity is permitted to make, the covered entity\'s duties with respect to protected health information, the privacy rights of individuals, and how individuals may exercise these rights.</p><p>A covered entity must provide the notice to individuals no later than the date of first service delivery and make a good faith effort to obtain a written acknowledgment of receipt of the notice.</p>'],
      ['title' => 'Privacy Rule — Individual Right of Access', 'section_number' => '§164.524', 'chapter' => 'Privacy Rule', 'severity' => 'high', 'plain_summary' => 'Patients have the right to access their own medical records within 30 days. Fee cannot exceed cost-based fee. Electronic records must be provided electronically if requested.', 'body' => '<p>Except as otherwise provided, an individual has a right of access to inspect and obtain a copy of protected health information about the individual in a designated record set. A covered entity must act on a request for access no later than 30 days after receipt of the request. The covered entity may extend the time for such action by no more than 30 days with written notice.</p>'],
      ['title' => 'Security Rule — General Requirements', 'section_number' => '§164.306', 'chapter' => 'Security Rule', 'severity' => 'critical', 'plain_summary' => 'HIPAA Security Rule requires covered entities to ensure confidentiality, integrity, and availability of all ePHI. Security measures must be "reasonable and appropriate" based on a risk analysis.', 'body' => '<p>A covered entity or business associate must: ensure the confidentiality, integrity, and availability of all electronic protected health information; protect against any reasonably anticipated threats or hazards to the security or integrity of such information; protect against any reasonably anticipated uses or disclosures of such information that are not permitted; and ensure compliance with this subpart by its workforce.</p>'],
      ['title' => 'Security Rule — Risk Analysis', 'section_number' => '§164.308(a)(1)', 'chapter' => 'Security Rule', 'severity' => 'critical', 'plain_summary' => 'Risk analysis is the foundation of HIPAA Security Rule compliance. Must be documented, current, and cover all ePHI wherever it lives. Most OCR enforcement actions cite missing or inadequate risk analysis.', 'body' => '<p>A covered entity must conduct an accurate and thorough assessment of the potential risks and vulnerabilities to the confidentiality, integrity, and availability of electronic protected health information held by the covered entity. Required implementation specifications: Risk analysis (Required); Risk management (Required); Sanction policy (Required); Information system activity review (Required).</p>'],
      ['title' => 'Security Rule — Audit Controls', 'section_number' => '§164.312(b)', 'chapter' => 'Security Rule', 'severity' => 'high', 'plain_summary' => 'Must log and monitor access to ePHI systems. Audit logs must be reviewed regularly — not just collected. Many OCR settlements involve lack of access auditing.', 'body' => '<p>Implement hardware, software, and/or procedural mechanisms that record and examine activity in information systems that contain or use electronic protected health information. Audit logs must be maintained, reviewed on a regular basis, and retained for a period of time sufficient to support the organization\'s business and security requirements.</p>'],
      ['title' => 'Breach Notification Rule — General Rule', 'section_number' => '§164.400-414', 'chapter' => 'Breach Notification Rule', 'severity' => 'critical', 'plain_summary' => 'After discovering a PHI breach: notify affected individuals within 60 days, notify HHS, and if 500+ in one state are affected, notify major media in that state.', 'body' => '<p>A covered entity shall, following the discovery of a breach of unsecured protected health information, notify each individual whose unsecured protected health information has been, or is reasonably believed to have been, accessed, acquired, used, or disclosed as a result of such breach no later than 60 calendar days after discovery. The covered entity must also notify the Secretary and, if the breach involves more than 500 individuals in a state, prominent media outlets serving that state.</p>'],
      ['title' => 'Business Associate Agreements', 'section_number' => '§164.504(e)', 'chapter' => 'Privacy Rule', 'severity' => 'critical', 'plain_summary' => 'Every vendor that touches PHI on your behalf needs a Business Associate Agreement (BAA). No BAA = direct HIPAA violation regardless of whether a breach occurred.', 'body' => '<p>A covered entity may disclose protected health information to a business associate and may allow a business associate to create, receive, maintain, or transmit protected health information on its behalf, if the covered entity obtains satisfactory assurances that the business associate will appropriately safeguard the information. Processing by a business associate must be governed by a written contract specifying the permitted and required uses and disclosures of protected health information.</p>'],
      ['title' => 'Workforce Training Requirements', 'section_number' => '§164.530(b)', 'chapter' => 'Privacy Rule', 'severity' => 'high', 'plain_summary' => 'All workforce members must receive HIPAA privacy training. New employees need it promptly after joining. Document who was trained and when — OCR investigators will ask.', 'body' => '<p>A covered entity must train all members of its workforce on the policies and procedures with respect to protected health information as necessary and appropriate for the members of the workforce to carry out their functions. Training must be provided: to each new member of the workforce within a reasonable period of time after joining; and to each member of the workforce whose functions are affected by a material change in policies within a reasonable period of time after the material change becomes effective.</p>'],
    ];
  }

  private function getCcpaSections(): array {
    return [
      ['title' => '§1798.100 — General Duty to Disclose', 'section_number' => '§1798.100', 'chapter' => 'Part 1', 'severity' => 'high', 'plain_summary' => 'California consumers can request to know what personal information you\'ve collected about them. Businesses must respond within 45 days.', 'body' => '<p>A consumer shall have the right to request that a business that collects a consumer\'s personal information disclose to that consumer the categories and specific pieces of personal information the business has collected. Businesses must respond to verified consumer requests within 45 calendar days of receipt.</p>'],
      ['title' => '§1798.105 — Right to Deletion', 'section_number' => '§1798.105', 'chapter' => 'Part 1', 'severity' => 'high', 'plain_summary' => 'California consumers can request deletion. Business must delete AND instruct service providers to delete. Nine exceptions apply including legal obligations.', 'body' => '<p>A consumer shall have the right to request that a business delete any personal information about the consumer which the business has collected from the consumer. A business that receives a verifiable consumer request for deletion shall delete the consumer\'s personal information from its records and direct any service providers and contractors to delete the consumer\'s personal information from their records.</p>'],
      ['title' => '§1798.110 — Right to Know What Is Collected', 'section_number' => '§1798.110', 'chapter' => 'Part 1', 'severity' => 'high', 'plain_summary' => 'Consumers can request not just what data you have, but where it came from, why you collected it, and who you share it with. Respond within 45 days.', 'body' => '<p>A consumer shall have the right to request that a business that collects personal information about the consumer disclose: the categories of personal information collected; the categories of sources from which collected; the business or commercial purpose for collecting or selling; the categories of third parties to whom disclosed; and the specific pieces of personal information collected about that consumer.</p>'],
      ['title' => '§1798.120 — Right to Opt-Out of Sale or Sharing', 'section_number' => '§1798.120', 'chapter' => 'Part 1', 'severity' => 'critical', 'plain_summary' => 'Consumers can opt out of sale or sharing of their personal information at any time. Must display "Do Not Sell or Share My Personal Information" link. Honor requests within 15 business days.', 'body' => '<p>A consumer shall have the right, at any time, to direct a business that sells personal information about the consumer to third parties not to sell the consumer\'s personal information. A business that has received a consumer\'s opt-out request may not ask the consumer to consent to the sale of personal information for a period of at least 12 months after the consumer\'s opt-out request.</p>'],
      ['title' => '§1798.121 — Right to Limit Use of Sensitive Personal Information', 'section_number' => '§1798.121', 'chapter' => 'Part 1 (CPRA)', 'severity' => 'high', 'plain_summary' => 'CPRA added a right to limit use of sensitive personal information: SSNs, financial accounts, health data, precise geolocation, biometrics, sexual orientation.', 'body' => '<p>A consumer shall have the right, at any time, to direct a business that collects sensitive personal information about the consumer to limit its use of the consumer\'s sensitive personal information to that use which is necessary to perform the services or provide the goods reasonably expected by an average consumer who requests those goods or services.</p>'],
      ['title' => '§1798.140 — Definitions', 'section_number' => '§1798.140', 'chapter' => 'Part 1', 'severity' => 'medium', 'plain_summary' => 'CCPA/CPRA applies to California businesses meeting any one threshold: $25M+ revenue, 100,000+ consumers\' data bought/sold, or 50%+ revenue from selling data.', 'body' => '<p>"Business" means a sole proprietorship, partnership, limited liability company, corporation, or other legal entity that collects consumers\' personal information, does business in California, and satisfies one or more of the following: annual gross revenues in excess of $25,000,000; annually buys, sells, or shares the personal information of 100,000 or more consumers or households; or derives 50 percent or more of its annual revenues from selling or sharing consumers\' personal information.</p>'],
      ['title' => '§1798.150 — Private Right of Action', 'section_number' => '§1798.150', 'chapter' => 'Part 1', 'severity' => 'critical', 'plain_summary' => 'CCPA private right of action for data breaches: $100–$750 per person per incident or actual damages, whichever is greater. Class action risk is significant.', 'body' => '<p>Any consumer whose nonencrypted and nonredacted personal information is subject to an unauthorized access and exfiltration, theft, or disclosure as a result of the business\'s violation of the duty to implement and maintain reasonable security procedures and practices appropriate to the nature of the information may institute a civil action.</p><p>The consumer may recover damages in an amount not less than $100 and not greater than $750 per consumer per incident or actual damages, whichever is greater, injunctive or declaratory relief, and any other relief the court deems proper.</p>'],
    ];
  }

  private function getSoxSections(): array {
    return [
      ['title' => 'Section 302 — Corporate Responsibility for Financial Reports', 'section_number' => '§302', 'chapter' => 'Title III', 'severity' => 'critical', 'plain_summary' => 'CEO and CFO must personally certify every quarterly and annual report. False certification: up to 10 years (negligent) or 20 years (willful) in prison.', 'body' => '<p>The principal executive officer or officers and the principal financial officer or officers of each issuer shall certify in each annual or quarterly report that they have reviewed the report; it does not contain any untrue statement of a material fact; the financial statements fairly present in all material respects the financial condition and results of operations; and that they have disclosed any material weaknesses in internal controls.</p>'],
      ['title' => 'Section 404 — Management Assessment of Internal Controls', 'section_number' => '§404', 'chapter' => 'Title IV', 'severity' => 'critical', 'plain_summary' => 'Section 404 is the most compliance-intensive part of SOX. Annual management assessment of internal controls over financial reporting (ICFR) required. External auditors must attest for large companies.', 'body' => '<p>Each annual report shall contain an internal control report, which shall: state the responsibility of management for establishing and maintaining an adequate internal control structure and procedures for financial reporting; and contain an assessment, as of the end of the most recent fiscal year, of the effectiveness of the internal control structure and procedures for financial reporting. Each registered public accounting firm that prepares the audit report shall attest to, and report on, the assessment made by management.</p>'],
      ['title' => 'Section 409 — Real Time Issuer Disclosures', 'section_number' => '§409', 'chapter' => 'Title IV', 'severity' => 'high', 'plain_summary' => 'Material changes must be disclosed "rapidly and currently" — within 4 business days via Form 8-K. Since 2023 SEC rules, this explicitly includes significant cyberattacks.', 'body' => '<p>Each issuer reporting under the Securities Exchange Act of 1934 shall disclose to the public on a rapid and current basis such additional information concerning material changes in the financial condition or operations of the issuer, in plain English, as the Commission determines is necessary or useful for the protection of investors and in the public interest.</p>'],
      ['title' => 'Section 802 — Criminal Penalties for Altering Documents', 'section_number' => '§802', 'chapter' => 'Title VIII', 'severity' => 'critical', 'plain_summary' => 'Destroying or altering documents related to a federal investigation is a felony — up to 20 years in prison. Legal holds are not optional once an investigation is possible.', 'body' => '<p>Whoever knowingly alters, destroys, mutilates, conceals, covers up, falsifies, or makes a false entry in any record, document, or tangible object with the intent to impede, obstruct, or influence the investigation or proper administration of any matter within the jurisdiction of any department or agency of the United States shall be fined under this title, imprisoned not more than 20 years, or both.</p>'],
      ['title' => 'Section 906 — Corporate Responsibility for Financial Reports (Criminal Penalties)', 'section_number' => '§906', 'chapter' => 'Title IX', 'severity' => 'critical', 'plain_summary' => 'Criminal version of Section 302. Knowing violation: up to $1M fine / 10 years. Willful violation: up to $5M fine / 20 years. Signed with every 10-K and 10-Q filing.', 'body' => '<p>Whoever certifies any statement knowing that the periodic report accompanying the statement does not comport with all the requirements set forth in this section shall be fined not more than $1,000,000 or imprisoned not more than 10 years, or both.</p><p>Whoever willfully certifies any statement knowing that the periodic report does not comport with all the requirements shall be fined not more than $5,000,000, or imprisoned not more than 20 years, or both.</p>'],
    ];
  }

  private function getFerpaSections(): array {
    return [
      ['title' => 'FERPA — General Provisions and Rights', 'section_number' => '20 U.S.C. §1232g', 'chapter' => 'General', 'severity' => 'high', 'plain_summary' => 'FERPA gives parents (and students over 18 or in college) the right to inspect and correct education records. Schools violating FERPA risk losing federal funding.', 'body' => '<p>No funds shall be made available under any applicable program to any educational agency or institution which has a policy of denying, or which effectively prevents, the parents of students the right to inspect and review the education records of their students.</p><p>When a student reaches 18 years of age or attends a postsecondary institution, the rights accorded to and the consent required of parents under FERPA transfer from the parents to the student.</p>'],
      ['title' => 'FERPA — Disclosure Without Consent: School Official Exception', 'section_number' => '34 CFR §99.31', 'chapter' => 'Permitted Disclosures', 'severity' => 'critical', 'plain_summary' => 'Cloud vendors can access student data WITHOUT consent if they perform a school function, are under direct control, and are bound by FERPA. The DPA must explicitly require FERPA compliance.', 'body' => '<p>An educational agency or institution may disclose personally identifiable information from an education record without consent if the disclosure is to school officials, including contractors and consultants, whom the agency has determined to have legitimate educational interests. Outside parties must: perform an institutional service or function the agency would otherwise use employees for; be under the direct control of the agency with respect to the use and maintenance of education records; and be subject to the requirements governing the use and re-disclosure of personally identifiable information.</p>'],
      ['title' => 'FERPA — Directory Information', 'section_number' => '34 CFR §99.37', 'chapter' => 'Permitted Disclosures', 'severity' => 'medium', 'plain_summary' => 'Schools can designate certain information as "directory information" and disclose it without consent — unless the student opts out. Schools must notify students annually of opt-out rights.', 'body' => '<p>An educational agency or institution may disclose directory information if it has given public notice to parents and eligible students of: the types of personally identifiable information designated as directory information; a parent\'s or eligible student\'s right to refuse to let the agency designate any or all of those types of information about the student as directory information; and the period of time within which a parent or eligible student has to notify the institution in writing that he or she does not want any or all of those types of information designated as directory information.</p>'],
    ];
  }

  private function getAdaSections(): array {
    return [
      ['title' => 'ADA Title III — Public Accommodations and Commercial Facilities', 'section_number' => '42 U.S.C. §12182', 'chapter' => 'Title III', 'severity' => 'critical', 'plain_summary' => 'ADA Title III requires businesses\' websites to be accessible. Courts and DOJ consistently hold websites are covered. Inaccessible websites can trigger DOJ enforcement and private lawsuits.', 'body' => '<p>No individual shall be discriminated against on the basis of disability in the full and equal enjoyment of the goods, services, facilities, privileges, advantages, or accommodations of any place of public accommodation. The term "discrimination" includes a failure to take such steps as may be necessary to ensure that no individual with a disability is excluded, denied services, segregated or otherwise treated differently than other individuals because of the absence of auxiliary aids and services.</p>'],
      ['title' => 'Section 508 — Electronic and Information Technology', 'section_number' => '29 U.S.C. §794d', 'chapter' => 'Section 508', 'severity' => 'critical', 'plain_summary' => 'Section 508 requires all federal agencies\' electronic systems to be accessible. Applies to any company selling software or technology to the federal government. 2017 refresh aligned with WCAG 2.0 AA.', 'body' => '<p>When developing, procuring, maintaining, or using electronic and information technology, each Federal department or agency shall ensure, unless an undue burden would be imposed, that the electronic and information technology allows individuals with disabilities to have access to and use of information and data that is comparable to the access to and use of the information and data by individuals without disabilities, whether they are Federal employees or members of the public seeking information from a Federal department or agency.</p>'],
      ['title' => 'WCAG 2.1 AA — Perceivable', 'section_number' => 'WCAG 2.1 — Principle 1', 'chapter' => 'WCAG 2.1', 'severity' => 'high', 'plain_summary' => 'All information must be perceivable. Every image needs alt text, videos need captions, text must meet 4.5:1 contrast ratio, content must reflow at 320px width.', 'body' => '<p>Information and user interface components must be presentable to users in ways they can perceive. This includes: providing text alternatives for all non-text content; providing alternatives for time-based media; creating content that can be presented in different ways without losing information; and making it easier for users to see and hear content by separating foreground from background and ensuring sufficient contrast.</p>'],
      ['title' => 'WCAG 2.1 AA — Operable', 'section_number' => 'WCAG 2.1 — Principle 2', 'chapter' => 'WCAG 2.1', 'severity' => 'high', 'plain_summary' => 'All functionality must work without a mouse. Keyboard accessible, visible focus indicators, skip navigation links required. Touch targets minimum 44x44 CSS pixels.', 'body' => '<p>User interface components and navigation must be operable. This includes: making all functionality available from a keyboard; providing users enough time to read and use content; not designing content that causes seizures or physical reactions; providing ways to help users navigate and find content; and making it easier for users to operate functionality through various inputs beyond keyboard.</p>'],
    ];
  }

  private function getFedrampSections(): array {
    return [
      ['title' => 'FedRAMP Authorization Basics', 'section_number' => 'FedRAMP-AB', 'chapter' => 'Authorization', 'severity' => 'critical', 'plain_summary' => 'FedRAMP is mandatory for cloud services used by federal agencies. Based on NIST SP 800-53. Authorization takes 12-24 months. Once authorized, any agency can use your service ("do once, use many").', 'body' => '<p>FedRAMP standardizes security assessment, authorization, and continuous monitoring for cloud products and services used by the US federal government. Three authorization paths: Agency Authorization (one agency sponsors), Joint Authorization Board (JAB) authorization (most rigorous, highest reuse), and FedRAMP Tailored (for Low-impact SaaS). Impact levels — Low, Moderate, High — are determined by the sensitivity of the data processed by the system.</p>'],
      ['title' => 'FedRAMP Continuous Monitoring', 'section_number' => 'FedRAMP-CM', 'chapter' => 'Continuous Monitoring', 'severity' => 'high', 'plain_summary' => 'FedRAMP authorization requires ongoing monitoring: monthly vulnerability scans, annual pen tests, significant change notifications, and incident reporting to US-CERT within 1 hour.', 'body' => '<p>Once a cloud service offering receives FedRAMP authorization, the cloud service provider must perform ongoing continuous monitoring. Requirements include: monthly vulnerability scanning of operating systems, web applications, and databases; annual penetration testing; significant change notifications; annual security assessment of a subset of controls; incident reporting within 1 hour for US-CERT reportable incidents; and regular Plan of Action and Milestones (POA&M) updates.</p>'],
    ];
  }

  private function getPciDssSections(): array {
    return [
      ['title' => 'Requirement 1 — Install and Maintain Network Security Controls', 'section_number' => 'PCI DSS Req. 1', 'chapter' => 'Build and Maintain a Secure Network', 'severity' => 'critical', 'plain_summary' => 'Firewalls and network segmentation isolate the cardholder data environment (CDE). All traffic in/out must be justified and documented. NSC rules reviewed every 6 months.', 'body' => '<p>Network security controls (NSCs) are a foundational component of network security. All NSCs must restrict inbound and outbound traffic to only that which is necessary. NSCs between the cardholder data environment (CDE) and all other networks must be implemented and documented. Public requirement objectives include: processes for installing and maintaining network security controls are defined; NSCs are configured and maintained; and access to system components in the CDE is restricted.</p>'],
      ['title' => 'Requirement 3 — Protect Stored Account Data', 'section_number' => 'PCI DSS Req. 3', 'chapter' => 'Protect Cardholder Data', 'severity' => 'critical', 'plain_summary' => 'CVV/CVC codes must NEVER be stored after authorization. PAN must be stored encrypted or truncated. Best strategy: tokenize and don\'t store card data at all.', 'body' => '<p>Protection methods such as encryption, truncation, masking, and hashing are critical components of cardholder data protection. The primary account number (PAN) must be unreadable anywhere it is stored. Sensitive authentication data including the full magnetic stripe, CAV2/CVC2/CVV2/CID code, and PIN/PIN block must not be stored after authorization, even if encrypted. Requirement 3.4: PANs must be rendered unreadable anywhere they are stored using strong cryptography, index tokens, or truncation.</p>'],
      ['title' => 'Requirement 6 — Develop and Maintain Secure Systems and Software', 'section_number' => 'PCI DSS Req. 6', 'chapter' => 'Maintain a Vulnerability Management Program', 'severity' => 'critical', 'plain_summary' => 'Patch critical vulnerabilities within 1 month. Custom code must follow secure coding practices. Web applications must have WAF or undergo code review + pen testing.', 'body' => '<p>All system components are protected from known vulnerabilities by installing applicable security patches and updates. Critical patches are installed within one month of release. Bespoke and custom software are developed securely using secure coding guidelines. Web-facing applications are protected against known attacks including those defined in OWASP Top 10. Third-party software inventories must be maintained and monitored for vulnerabilities.</p>'],
      ['title' => 'Requirement 8 — Identify Users and Authenticate Access', 'section_number' => 'PCI DSS Req. 8', 'chapter' => 'Implement Strong Access Control Measures', 'severity' => 'critical', 'plain_summary' => 'PCI DSS v4.0: MFA required for ALL access to CDE (not just admins). Passwords must be at least 12 characters. Shared accounts prohibited. All CDE accounts reviewed every 6 months.', 'body' => '<p>Two fundamental principles apply: establishing the identity of the user, and confirming through authentication that the entity is who it claims to be. Multi-factor authentication is required for: all non-console administrative access to the CDE; all remote network access originating from outside the entity\'s network; and in PCI DSS v4.0, all user access to the CDE. Passwords for CDE accounts must be at least 12 characters. Shared or group accounts are prohibited for CDE access.</p>'],
      ['title' => 'Requirement 10 — Log and Monitor All Access to System Components', 'section_number' => 'PCI DSS Req. 10', 'chapter' => 'Regularly Monitor and Test Networks', 'severity' => 'high', 'plain_summary' => 'Log everything in the CDE. Protect logs from modification. Retain for 12 months with 3 months immediately available. Implement real-time alerting for suspicious activity.', 'body' => '<p>Logging mechanisms and the ability to track user activities are critical in preventing, detecting, or minimizing the impact of a data compromise. Audit logs must capture: user identification, type of event, date and time, success or failure indication, origination of event, and identity of affected data or system component. Audit logs must be protected from modification, retained for 12 months with at least 3 months immediately available for analysis.</p>'],
      ['title' => 'Requirement 12 — Support Information Security with Organizational Policies and Programs', 'section_number' => 'PCI DSS Req. 12', 'chapter' => 'Maintain an Information Security Policy', 'severity' => 'high', 'plain_summary' => 'Comprehensive security policy reviewed annually. Incident response plan mandatory, tested annually, must specifically address card data breaches and notification to card brands.', 'body' => '<p>A strong security policy sets the security tone for the whole entity and informs personnel what is expected of them. The information security policy must be reviewed at least annually. Requirement 12.10: Incident response plans must exist and be tested at least annually. The plan must include procedures for containing and minimizing damage, assessing affected systems, identifying the cause of compromise, reporting the incident, restoring operations, and preventing a recurrence. Card brand contacts must be notified per their requirements.</p>'],
    ];
  }

  // ---------------------------------------------------------------------------
  // Case data
  // ---------------------------------------------------------------------------

  private function getIcoCaseData(): array {
    return [
      [
        'title' => 'British Airways — GDPR Data Breach Fine (£20M)',
        'body' => '<p>The ICO issued British Airways a £20 million fine following a 2018 data breach affecting approximately 400,000 customers. Attackers harvested customer and staff data including login credentials, payment card details, and personal information. The ICO found British Airways had poor security arrangements including inadequate authentication, poor patch management, and insufficient monitoring. The original proposed fine was £183.39 million, reduced due to COVID-19 economic impact considerations.</p>',
        'regulations' => ['GDPR'],
        'enforcement_body' => 'ICO (UK)',
        'industry' => 'Technology',
        'penalty_amount' => '£20M',
        'penalty_numeric' => 25000000,
        'date' => '2020-10-16',
        'key_facts' => '<p>Attackers injected malicious code into British Airways\' website and mobile app harvesting card payment details during booking. 400,000 customer records were affected. The breach ran undetected from June to September 2018. British Airways did not detect the breach themselves — they were notified by a security researcher.</p>',
        'lessons' => '<p>Web application monitoring and file integrity monitoring are essential. Payment card harvesting (Magecart attacks) are detectable with proper monitoring. Subprocessor security must be reviewed — the breach involved compromised JavaScript from a third-party vendor.</p>',
        'source_url' => 'https://ico.org.uk/action-weve-taken/enforcement/british-airways/',
      ],
      [
        'title' => 'Marriott International — GDPR Fine (£18.4M)',
        'body' => '<p>The ICO fined Marriott International £18.4 million following a data breach that began in 2014 and ran through 2018. The breach originated in the reservation systems of Starwood Hotels prior to Marriott\'s acquisition of Starwood in 2016. Approximately 339 million guest records worldwide were affected, including 7 million UK residents.</p>',
        'regulations' => ['GDPR'],
        'enforcement_body' => 'ICO (UK)',
        'industry' => 'Retail',
        'penalty_amount' => '£18.4M',
        'penalty_numeric' => 23000000,
        'date' => '2020-10-30',
        'key_facts' => '<p>The breach originated in Starwood\'s systems before Marriott acquired the company. Marriott failed to conduct adequate security due diligence of Starwood\'s IT systems during acquisition. The attacker had persistent access to Starwood\'s reservation system for 4 years before detection.</p>',
        'lessons' => '<p>M&A cybersecurity due diligence is critical. Inheriting an acquisition means inheriting their security vulnerabilities. Security assessments including penetration testing should be conducted before and after major acquisitions. Post-acquisition security integration timelines must be aggressive.</p>',
        'source_url' => 'https://ico.org.uk/action-weve-taken/enforcement/marriott-international-inc/',
      ],
      [
        'title' => 'Meta (Facebook) — GDPR Fine for EU-US Data Transfers (€1.2B)',
        'body' => '<p>Ireland\'s Data Protection Commission (DPC), acting as lead supervisory authority, fined Meta Platforms €1.2 billion for transferring personal data of Facebook users from the EU/EEA to the United States in violation of GDPR Chapter V. This is the largest GDPR fine ever issued. The decision followed the Schrems II judgment which invalidated the EU-US Privacy Shield in 2020.</p>',
        'regulations' => ['GDPR'],
        'enforcement_body' => 'ICO (UK)',
        'industry' => 'Technology',
        'penalty_amount' => '€1.2B',
        'penalty_numeric' => 1300000000,
        'date' => '2023-05-22',
        'key_facts' => '<p>Meta transferred EU user data to US servers where it could be accessed by US intelligence agencies under FISA Section 702 and EO 12333. After Schrems II invalidated Privacy Shield, Meta continued US transfers relying on Standard Contractual Clauses without implementing adequate supplementary measures to protect EU data from US surveillance.</p>',
        'lessons' => '<p>International data transfers require active monitoring of transfer mechanism validity. Post-Schrems II, SCCs alone are insufficient for US transfers without Transfer Impact Assessments (TIAs). Organizations must assess whether destination country surveillance laws undermine SCC protections.</p>',
        'source_url' => 'https://www.dataprotection.ie/en/news-media/press-releases/dpc-announces-decision-in-facebook-transfers-inquiry',
      ],
    ];
  }

  private function getHhsOcrCaseData(): array {
    return [
      [
        'title' => 'Advocate Aurora Health — Tracking Pixel HIPAA Settlement ($3M)',
        'body' => '<p>HHS OCR reached a $3 million settlement with Advocate Aurora Health, an Illinois-based healthcare system, for HIPAA violations related to the use of tracking technologies on their patient portal websites and applications. Advocate Aurora used tracking technologies including Meta Pixel and Google Analytics that disclosed protected health information to third-party vendors without patient authorization or business associate agreements.</p>',
        'regulations' => ['HIPAA'],
        'enforcement_body' => 'HHS OCR (US)',
        'industry' => 'Healthcare',
        'penalty_amount' => '$3.0M',
        'penalty_numeric' => 3000000,
        'date' => '2023-11-14',
        'key_facts' => '<p>Advocate Aurora installed third-party tracking code on their patient-facing website and patient portal. This code transmitted PHI — including IP addresses, appointment information, and health conditions — to Meta and Google without business associate agreements. Approximately 3 million patients were affected. HHS OCR found Advocate Aurora failed to conduct a risk analysis covering the tracking technology.</p>',
        'lessons' => '<p>Third-party tracking technologies on healthcare websites constitute a disclosure of PHI requiring BAAs. Healthcare organizations must audit all third-party code on websites where patients log in or enter health information. The 2022 HHS OCR Bulletin on tracking technologies explicitly addressed this issue. Many health systems were using similar tracking code.</p>',
        'source_url' => 'https://www.hhs.gov/hipaa/for-professionals/compliance-enforcement/agreements/advocate-aurora-health/index.html',
      ],
      [
        'title' => 'Banner Health — Network Segmentation Failure HIPAA Settlement ($1.25M)',
        'body' => '<p>HHS OCR settled with Banner Health for $1.25 million following a 2016 data breach affecting approximately 2.9 million individuals. Attackers gained access to Banner Health\'s payment card processing system through a phishing attack, then pivoted to systems containing PHI. OCR found Banner Health failed to conduct a thorough risk analysis, implement security measures, review information system activity, and implement technical security measures.</p>',
        'regulations' => ['HIPAA'],
        'enforcement_body' => 'HHS OCR (US)',
        'industry' => 'Healthcare',
        'penalty_amount' => '$1.25M',
        'penalty_numeric' => 1250000,
        'date' => '2023-02-14',
        'key_facts' => '<p>Attackers first compromised Banner Health\'s food and beverage payment processing systems at their healthcare facilities, then laterally moved to clinical systems containing PHI. The lack of network segmentation between food service point-of-sale systems and clinical systems allowed the lateral movement. OCR found inadequate risk analysis was the root cause.</p>',
        'lessons' => '<p>Network segmentation is critical in healthcare environments. Payment systems and clinical systems must be isolated. The risk analysis scope must include ALL systems that store, process, or transmit ePHI — including ancillary systems like food service and administrative systems in healthcare facilities.</p>',
        'source_url' => 'https://www.hhs.gov/hipaa/for-professionals/compliance-enforcement/agreements/banner/index.html',
      ],
      [
        'title' => 'Lafourche Medical Group — No Risk Analysis HIPAA Penalty ($480K)',
        'body' => '<p>HHS OCR imposed a $480,226 civil money penalty on Lafourche Medical Group for failure to conduct a HIPAA risk analysis. A phishing attack resulted in unauthorized access to an employee email account containing PHI of approximately 34,000 patients. The investigation found Lafourche had never conducted a risk analysis and lacked a security management process.</p>',
        'regulations' => ['HIPAA'],
        'enforcement_body' => 'HHS OCR (US)',
        'industry' => 'Healthcare',
        'penalty_amount' => '$480K',
        'penalty_numeric' => 480226,
        'date' => '2023-04-13',
        'key_facts' => '<p>A phishing email led to employee credential compromise. When OCR investigated, it found Lafourche had never conducted a required risk analysis — not once since HIPAA\'s security rule compliance date. The attacker accessed the email account for an unknown period with access to PHI of 34,000 patients.</p>',
        'lessons' => '<p>The risk analysis is mandatory, not optional, and not a one-time event. It must be updated when the environment changes. Small and mid-sized healthcare providers are equally subject to HIPAA. Email systems containing PHI are a primary target requiring MFA, DLP, and anti-phishing controls. OCR treats absence of risk analysis as a serious violation even if no breach occurred.</p>',
        'source_url' => 'https://www.hhs.gov/hipaa/for-professionals/compliance-enforcement/agreements/lafourche/index.html',
      ],
    ];
  }

  private function getFtcCaseData(): array {
    return [
      [
        'title' => 'Twitter/X — Repurposing Security Data for Ads FTC Settlement ($150M)',
        'body' => '<p>The FTC and DOJ reached a $150 million penalty settlement with Twitter for violating a 2011 FTC order and deceiving users about how their phone numbers and email addresses collected for security purposes were used. Twitter collected contact information for two-factor authentication, then used that data for targeted advertising without disclosing this to users.</p>',
        'regulations' => ['CCPA/CPRA'],
        'enforcement_body' => 'FTC (US)',
        'industry' => 'Technology',
        'penalty_amount' => '$150M',
        'penalty_numeric' => 150000000,
        'date' => '2022-05-25',
        'key_facts' => '<p>Twitter told users their phone numbers and email addresses were needed for account security (2FA). Twitter then used that data for targeted advertising without disclosing this to users. Approximately 140 million users were affected. The practice violated Twitter\'s own privacy policy representations and a prior 2011 FTC consent order requiring a comprehensive privacy program.</p>',
        'lessons' => '<p>Data collected for one purpose cannot be repurposed for another without disclosure and consent. Prior FTC consent orders create heightened obligations — violations carry significantly higher penalties. Security-purpose data (phone numbers for 2FA) is particularly sensitive to repurposing.</p>',
        'source_url' => 'https://www.ftc.gov/news-events/news/press-releases/2022/05/ftc-doj-charge-twitter-illegally-using-account-security-data-target-ads',
      ],
      [
        'title' => 'Amazon Ring — Employee Surveillance FTC Settlement ($5.8M)',
        'body' => '<p>The FTC settled with Ring LLC (an Amazon subsidiary) for $5.8 million and a comprehensive order following allegations that Ring allowed employees and contractors to access customers\' private videos without authorization. Ring employees used their access to watch videos of female customers in private spaces including bedrooms and bathrooms.</p>',
        'regulations' => ['CCPA/CPRA'],
        'enforcement_body' => 'FTC (US)',
        'industry' => 'Technology',
        'penalty_amount' => '$5.8M',
        'penalty_numeric' => 5800000,
        'date' => '2023-05-31',
        'key_facts' => '<p>Ring granted overly broad access to customer video data to employees and third-party contractors without legitimate business need. At least one employee watched thousands of videos from female customers. The FTC also found Ring failed to implement MFA, allowed credential stuffing attacks due to lack of rate limiting, and failed to implement basic security practices for a device with cameras in people\'s homes.</p>',
        'lessons' => '<p>Access controls for customer data must follow minimum necessary/least privilege principles. Video surveillance data warrants heightened protection. Third-party contractor access to sensitive customer data requires the same rigorous access controls as employee access. MFA and rate limiting are baseline security requirements for consumer devices.</p>',
        'source_url' => 'https://www.ftc.gov/news-events/news/press-releases/2023/05/ftc-says-amazon-ring-illegally-surveilled-customers-failed-stop-employees-contractors-from-accessing',
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Generation topic lists
  // ---------------------------------------------------------------------------

  private function getGuidanceTopics(): array {
    return [
      ['title' => 'GDPR Consent: What Counts and What Doesn\'t', 'regulations' => ['GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'HIPAA Breach Notification: Who, When, and How', 'regulations' => ['HIPAA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'CCPA Opt-Out Rights: Implementation Guide for Businesses', 'regulations' => ['CCPA/CPRA'], 'audience' => 'IT/Security', 'difficulty' => 'practitioner'],
      ['title' => 'SOX Section 404: IT General Controls for IT Teams', 'regulations' => ['SOX'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'PCI-DSS v4.0: What Changed and What You Need to Do', 'regulations' => ['PCI-DSS'], 'audience' => 'IT/Security', 'difficulty' => 'practitioner'],
      ['title' => 'FERPA and Cloud Computing: What Universities Need to Know', 'regulations' => ['FERPA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'Website Accessibility Under ADA Title III', 'regulations' => ['ADA/Section 508'], 'audience' => 'IT/Security', 'difficulty' => 'overview'],
      ['title' => 'FedRAMP Authorization: A Vendor\'s Guide', 'regulations' => ['FedRAMP'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'GDPR Data Transfers After Schrems II: SCCs, TIAs, and DPF', 'regulations' => ['GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'expert'],
      ['title' => 'What Counts as Personal Data Under GDPR?', 'regulations' => ['GDPR'], 'audience' => 'All', 'difficulty' => 'overview'],
      ['title' => 'HIPAA Business Associate Agreements: A Practical Guide', 'regulations' => ['HIPAA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'Responding to Data Subject Access Requests Under GDPR', 'regulations' => ['GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'HIPAA Security Rule: Risk Analysis Step-by-Step', 'regulations' => ['HIPAA'], 'audience' => 'IT/Security', 'difficulty' => 'practitioner'],
      ['title' => 'Board Reporting on Cyber Risk Under SOX and SEC Rules', 'regulations' => ['SOX'], 'audience' => 'Executive/Board', 'difficulty' => 'overview'],
      ['title' => 'GDPR Data Protection by Design: Engineering Requirements', 'regulations' => ['GDPR'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'CCPA vs GDPR: Key Differences for Multinational Companies', 'regulations' => ['CCPA/CPRA', 'GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'Student Data Privacy: FERPA, COPPA, and State Laws', 'regulations' => ['FERPA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'PCI-DSS Tokenization vs. Encryption: Which to Use', 'regulations' => ['PCI-DSS'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'WCAG 2.1 AA for Web Developers: Technical Requirements', 'regulations' => ['ADA/Section 508'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'Incident Response Planning Under HIPAA, GDPR, and PCI-DSS', 'regulations' => ['HIPAA', 'GDPR', 'PCI-DSS'], 'audience' => 'IT/Security', 'difficulty' => 'practitioner'],
      ['title' => 'GDPR Legitimate Interests: The Balancing Test Explained', 'regulations' => ['GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'expert'],
      ['title' => 'HIPAA Workforce Training: Requirements and Best Practices', 'regulations' => ['HIPAA'], 'audience' => 'HR', 'difficulty' => 'overview'],
      ['title' => 'California Consumer Rights Under CPRA: What Businesses Must Do', 'regulations' => ['CCPA/CPRA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'SOX IT Audit Preparation: A 90-Day Plan', 'regulations' => ['SOX'], 'audience' => 'IT/Security', 'difficulty' => 'practitioner'],
      ['title' => 'FedRAMP Continuous Monitoring: Ongoing Compliance Requirements', 'regulations' => ['FedRAMP'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
      ['title' => 'Vendor Risk Management Under GDPR and HIPAA', 'regulations' => ['GDPR', 'HIPAA'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'ADA Website Lawsuits: Risk Assessment for Businesses', 'regulations' => ['ADA/Section 508'], 'audience' => 'Executive/Board', 'difficulty' => 'overview'],
      ['title' => 'GDPR Data Protection Impact Assessments: When and How', 'regulations' => ['GDPR'], 'audience' => 'Legal/Compliance', 'difficulty' => 'practitioner'],
      ['title' => 'Employee Monitoring and Privacy: GDPR and US Law', 'regulations' => ['GDPR'], 'audience' => 'HR', 'difficulty' => 'practitioner'],
      ['title' => 'PCI-DSS Penetration Testing Requirements', 'regulations' => ['PCI-DSS'], 'audience' => 'IT/Security', 'difficulty' => 'expert'],
    ];
  }

  private function getChecklistTopics(): array {
    return [
      ['title' => 'GDPR Readiness Checklist for Data Controllers', 'regulation' => 'GDPR', 'audience' => 'Legal/Compliance'],
      ['title' => 'HIPAA Security Rule Checklist for IT Teams', 'regulation' => 'HIPAA', 'audience' => 'IT/Security'],
      ['title' => 'CCPA Compliance Checklist for California Businesses', 'regulation' => 'CCPA/CPRA', 'audience' => 'Legal/Compliance'],
      ['title' => 'SOX IT General Controls Checklist', 'regulation' => 'SOX', 'audience' => 'IT/Security'],
      ['title' => 'PCI-DSS Self-Assessment Preparation Checklist', 'regulation' => 'PCI-DSS', 'audience' => 'IT/Security'],
      ['title' => 'FERPA Compliance Checklist for Higher Education', 'regulation' => 'FERPA', 'audience' => 'Legal/Compliance'],
      ['title' => 'Section 508 Web Accessibility Audit Checklist', 'regulation' => 'ADA/Section 508', 'audience' => 'IT/Security'],
      ['title' => 'FedRAMP Authorization Readiness Checklist', 'regulation' => 'FedRAMP', 'audience' => 'IT/Security'],
      ['title' => 'GDPR Breach Response Checklist', 'regulation' => 'GDPR', 'audience' => 'Legal/Compliance'],
      ['title' => 'HIPAA Breach Notification Checklist', 'regulation' => 'HIPAA', 'audience' => 'Legal/Compliance'],
      ['title' => 'GDPR Data Subject Request Response Checklist', 'regulation' => 'GDPR', 'audience' => 'Legal/Compliance'],
      ['title' => 'SOX Annual Audit Preparation Checklist', 'regulation' => 'SOX', 'audience' => 'Executive/Board'],
      ['title' => 'PCI-DSS Merchant Onboarding Security Checklist', 'regulation' => 'PCI-DSS', 'audience' => 'IT/Security'],
      ['title' => 'HIPAA New Employee Onboarding Compliance Checklist', 'regulation' => 'HIPAA', 'audience' => 'HR'],
      ['title' => 'GDPR Vendor Due Diligence Checklist', 'regulation' => 'GDPR', 'audience' => 'Legal/Compliance'],
    ];
  }

  private function getComparisonTopics(): array {
    return [
      ['title' => 'Data Breach Notification: GDPR vs HIPAA vs CCPA', 'regulations' => ['GDPR', 'HIPAA', 'CCPA/CPRA']],
      ['title' => 'Data Subject Rights: GDPR vs CCPA vs FERPA', 'regulations' => ['GDPR', 'CCPA/CPRA', 'FERPA']],
      ['title' => 'Security Requirements: HIPAA vs PCI-DSS vs FedRAMP', 'regulations' => ['HIPAA', 'PCI-DSS', 'FedRAMP']],
      ['title' => 'Encryption Requirements Across Major Frameworks', 'regulations' => ['GDPR', 'HIPAA', 'PCI-DSS', 'FedRAMP']],
      ['title' => 'Audit and Logging Requirements: SOX vs HIPAA vs PCI-DSS', 'regulations' => ['SOX', 'HIPAA', 'PCI-DSS']],
      ['title' => 'Penalties and Enforcement: GDPR vs HIPAA vs CCPA', 'regulations' => ['GDPR', 'HIPAA', 'CCPA/CPRA']],
      ['title' => 'Vendor/Processor Requirements: GDPR vs HIPAA vs PCI-DSS', 'regulations' => ['GDPR', 'HIPAA', 'PCI-DSS']],
      ['title' => 'Data Retention Requirements Across Frameworks', 'regulations' => ['GDPR', 'HIPAA', 'SOX', 'FERPA']],
      ['title' => 'Right to Delete vs Right to Erasure: GDPR vs CCPA', 'regulations' => ['GDPR', 'CCPA/CPRA']],
      ['title' => 'Consent Requirements: GDPR vs HIPAA vs CCPA', 'regulations' => ['GDPR', 'HIPAA', 'CCPA/CPRA']],
    ];
  }

  private function getGlossaryTerms(): array {
    return [
      ['title' => 'Personal Data / Personal Information', 'regulations' => ['GDPR', 'CCPA/CPRA', 'HIPAA']],
      ['title' => 'Data Controller', 'regulations' => ['GDPR']],
      ['title' => 'Data Processor', 'regulations' => ['GDPR']],
      ['title' => 'Business Associate', 'regulations' => ['HIPAA']],
      ['title' => 'Protected Health Information (PHI)', 'regulations' => ['HIPAA']],
      ['title' => 'Consent', 'regulations' => ['GDPR', 'HIPAA', 'CCPA/CPRA']],
      ['title' => 'Data Breach', 'regulations' => ['GDPR', 'HIPAA', 'CCPA/CPRA']],
      ['title' => 'Data Subject Rights', 'regulations' => ['GDPR', 'CCPA/CPRA', 'FERPA']],
      ['title' => 'Legitimate Interests', 'regulations' => ['GDPR']],
      ['title' => 'Data Protection Officer (DPO)', 'regulations' => ['GDPR']],
      ['title' => 'Covered Entity', 'regulations' => ['HIPAA']],
      ['title' => 'Minimum Necessary Standard', 'regulations' => ['HIPAA']],
      ['title' => 'Cardholder Data Environment (CDE)', 'regulations' => ['PCI-DSS']],
      ['title' => 'Primary Account Number (PAN)', 'regulations' => ['PCI-DSS']],
      ['title' => 'Education Records', 'regulations' => ['FERPA']],
      ['title' => 'Directory Information', 'regulations' => ['FERPA']],
      ['title' => 'Internal Controls Over Financial Reporting (ICFR)', 'regulations' => ['SOX']],
      ['title' => 'Material Weakness', 'regulations' => ['SOX']],
      ['title' => 'Pseudonymization', 'regulations' => ['GDPR']],
      ['title' => 'Tokenization', 'regulations' => ['PCI-DSS']],
    ];
  }

  // -------------------------------------------------------------------------
  // Generate clean URL path aliases for all custom content types.
  // -------------------------------------------------------------------------
  #[CLI\Command(name: 'complianceiq:generate-aliases', aliases: ['ciq-aliases'])]
  #[CLI\Usage(name: 'drush complianceiq:generate-aliases', description: 'Generate path aliases for all custom content nodes')]
  public function generateAliases(): void {
    $framework_slugs = [
      'GDPR'            => 'gdpr',
      'HIPAA'           => 'hipaa',
      'CCPA/CPRA'       => 'ccpa',
      'SOX'             => 'sox',
      'PCI-DSS'         => 'pci-dss',
      'FERPA'           => 'ferpa',
      'ADA/Section 508' => 'ada-508',
      'FedRAMP'         => 'fedramp',
    ];
    $audience_slugs = [
      'Legal/Compliance' => 'legal',
      'IT/Security'      => 'it',
      'Executive/Board'  => 'executive',
      'HR'               => 'hr',
      'All'              => 'all',
    ];

    $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
    $node_storage  = \Drupal::entityTypeManager()->getStorage('node');
    $types = ['regulation_section', 'guidance_article', 'enforcement_case',
              'checklist', 'comparison', 'glossary_term'];

    $nids = \Drupal::entityQuery('node')
      ->condition('type', $types, 'IN')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $count = 0;
    foreach ($node_storage->loadMultiple($nids) as $node) {
      $slug        = $this->toSlug($node->getTitle());
      $system_path = '/node/' . $node->id();

      $alias = match($node->bundle()) {
        'regulation_section' => (function() use ($node, $slug, $framework_slugs) {
          $fw = $node->field_regulation->entity?->getName() ?? '';
          $fw_slug = $framework_slugs[$fw] ?? 'general';
          return "/regulations/$fw_slug/$slug";
        })(),
        'guidance_article' => (function() use ($node, $slug, $audience_slugs) {
          $aud = $node->field_audience->entity?->getName() ?? '';
          $aud_slug = $audience_slugs[$aud] ?? 'general';
          return "/guidance/$aud_slug/$slug";
        })(),
        'enforcement_case' => "/enforcement/$slug",
        'checklist'        => "/checklists/$slug",
        'comparison'       => "/comparisons/$slug",
        'glossary_term'    => "/glossary/$slug",
        default            => null,
      };

      if (!$alias) {
        continue;
      }

      foreach ($alias_storage->loadByProperties(['path' => $system_path]) as $e) {
        $e->delete();
      }

      $alias_storage->create([
        'path'     => $system_path,
        'alias'    => $alias,
        'langcode' => 'en',
      ])->save();
      $count++;
    }

    \Drupal::service('path_alias.manager')->cacheClear();
    $this->logger()->success("Created $count path aliases.");
  }

  private function toSlug(string $title): string {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
  }

}
