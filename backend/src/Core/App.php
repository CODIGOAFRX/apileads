<?php

declare(strict_types=1);

namespace Leads\Core;

use Leads\Application\AuthService;
use Leads\Application\CatalogService;
use Leads\Application\ExportService;
use Leads\Application\ImportService;
use Leads\Application\LeadPresenter;
use Leads\Application\LeadService;
use Leads\Application\MailAccountService;
use Leads\Application\MailService;
use Leads\Domain\Import\ColumnMapper;
use Leads\Domain\Import\DateParser;
use Leads\Domain\Lead\BusinessCalendar;
use Leads\Domain\Lead\LeadActionPolicy;
use Leads\Domain\Lead\LeadWorkflow;
use Leads\Domain\Lead\WorkflowSettings;
use Leads\Domain\Mail\MailTransport;
use Leads\Domain\Mail\TemplateRenderer;
use Leads\Infrastructure\Mail\LogTransport;
use Leads\Infrastructure\Mail\MimeBuilder;
use Leads\Infrastructure\Mail\NativeMailTransport;
use Leads\Infrastructure\Mail\SmtpSettings;
use Leads\Infrastructure\Mail\SmtpTransport;
use Leads\Infrastructure\Persistence\ActivityRepository;
use Leads\Infrastructure\Persistence\CatalogRepository;
use Leads\Infrastructure\Persistence\Database;
use Leads\Infrastructure\Persistence\ImportRepository;
use Leads\Infrastructure\Persistence\LeadRepository;
use Leads\Infrastructure\Persistence\LoginAttemptRepository;
use Leads\Infrastructure\Persistence\MailAccountRepository;
use Leads\Infrastructure\Persistence\MailRepository;
use Leads\Infrastructure\Persistence\UserRepository;
use Leads\Infrastructure\Security\JwtService;
use Leads\Infrastructure\Security\SecretBox;
use Leads\Infrastructure\Spreadsheet\SpreadsheetReader;
use PDO;

/**
 * Contenedor de dependencias. Crea cada servicio una sola vez y permite
 * sustituir base de datos, reloj y correo en las pruebas.
 */
final class App
{
    private array $instances = [];
    /** Con un transporte inyectado (pruebas) nunca se envía por el buzón de nadie. */
    private readonly bool $transportInjected;

    public function __construct(
        private readonly string $rootDir,
        private ?PDO $pdo = null,
        private ?Clock $clock = null,
        private ?MailTransport $mailTransport = null,
    ) {
        $this->transportInjected = $mailTransport !== null;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    public function timezone(): string
    {
        return Env::get('APP_TIMEZONE', 'Europe/Madrid');
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect(Env::required('DB_DSN'), Env::get('DB_USER'), Env::get('DB_PASSWORD', ''));
    }

    public function clock(): Clock
    {
        return $this->clock ??= new SystemClock();
    }

    public function mailTransport(): MailTransport
    {
        return $this->mailTransport ??= $this->buildMailTransport();
    }

    public function auth(): AuthService
    {
        return $this->once(AuthService::class, fn () => new AuthService(
            new UserRepository($this->pdo()),
            new LoginAttemptRepository($this->pdo()),
            new JwtService(Env::required('JWT_SECRET'), $this->clock(), Env::int('JWT_TTL_SECONDS', 43200)),
            $this->clock(),
        ));
    }

    public function workflowSettings(): WorkflowSettings
    {
        return $this->once(WorkflowSettings::class, fn () => new WorkflowSettings(
            maxAttempts: Env::int('MAX_INTENTOS_SIN_RESPUESTA', 6),
            lockMinutes: Env::int('MINUTOS_BLOQUEO_LLAMADA', 5),
        ));
    }

    public function leadRepository(): LeadRepository
    {
        return $this->once(LeadRepository::class, fn () => new LeadRepository($this->pdo()));
    }

    public function catalogRepository(): CatalogRepository
    {
        return $this->once(CatalogRepository::class, fn () => new CatalogRepository($this->pdo()));
    }

    public function activityRepository(): ActivityRepository
    {
        return $this->once(ActivityRepository::class, fn () => new ActivityRepository($this->pdo()));
    }

    public function leads(): LeadService
    {
        return $this->once(LeadService::class, fn () => new LeadService(
            $this->leadRepository(),
            $this->activityRepository(),
            $this->catalogRepository(),
            new LeadWorkflow(
                new BusinessCalendar($this->timezone(), Env::get('HORA_REAPERTURA', '09:00'), Env::list('FESTIVOS')),
                $this->workflowSettings(),
            ),
            $this->workflowSettings(),
            new LeadPresenter(
                new LeadActionPolicy(),
                Env::get('WHATSAPP_MENSAJE', 'Hola {nombre}, soy {comercial} de Estación Diseño. Te he intentado llamar por tu interés en {estudio}. ¿Cuándo te viene bien que hablemos?'),
            ),
            $this->clock(),
            $this->timezone(),
        ));
    }

    public function imports(): ImportService
    {
        return $this->once(ImportService::class, fn () => new ImportService(
            $this->pdo(),
            $this->leads(),
            $this->leadRepository(),
            $this->catalogRepository(),
            new ImportRepository($this->pdo()),
            new ColumnMapper(new DateParser($this->timezone())),
            $this->clock(),
            $this->timezone(),
        ));
    }

    public function spreadsheetReader(): SpreadsheetReader
    {
        return new SpreadsheetReader();
    }

    public function mail(): MailService
    {
        return $this->once(MailService::class, fn () => new MailService(
            new MailRepository($this->pdo()),
            $this->activityRepository(),
            $this->catalogRepository(),
            $this->leads(),
            $this->mailTransport(),
            new TemplateRenderer(),
            $this->clock(),
            Env::get('MAIL_REPLY_TO'),
            $this->mailAccounts(),
            // En local (MAIL_TRANSPORT=log) tampoco sale nada por los buzones de las comerciales.
            !$this->transportInjected && Env::get('MAIL_TRANSPORT', 'log') !== 'log',
        ));
    }

    public function mailAccounts(): MailAccountService
    {
        return $this->once(MailAccountService::class, fn () => new MailAccountService(
            new MailAccountRepository($this->pdo()),
            new UserRepository($this->pdo()),
            new SecretBox(Env::get('MAIL_ACCOUNTS_KEY') ?? Env::required('JWT_SECRET')),
            $this->clock(),
        ));
    }

    public function catalog(): CatalogService
    {
        return $this->once(CatalogService::class, fn () => new CatalogService($this->catalogRepository()));
    }

    public function exports(): ExportService
    {
        return $this->once(ExportService::class, fn () => new ExportService($this->leadRepository(), $this->clock(), $this->timezone()));
    }

    private function buildMailTransport(): MailTransport
    {
        $from = Env::get('MAIL_FROM', 'info@estaciondiseno.es');
        $mime = new MimeBuilder($from, Env::get('MAIL_FROM_NAME', 'Estación Diseño'));

        return match (Env::get('MAIL_TRANSPORT', 'log')) {
            'smtp' => new SmtpTransport($mime, new SmtpSettings(
                host: Env::required('MAIL_HOST'),
                port: Env::int('MAIL_PORT', 587),
                encryption: Env::get('MAIL_ENCRYPTION', 'tls'),
                username: Env::get('MAIL_USERNAME', ''),
                password: Env::get('MAIL_PASSWORD', ''),
                fromEmail: $from,
            )),
            'mail' => new NativeMailTransport($mime, $from),
            default => new LogTransport($this->rootDir . '/storage/logs/correos.log'),
        };
    }

    private function once(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }
}
