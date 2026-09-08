<?php
defined( 'ABSPATH' ) || exit;

class Newsgame_Settings {

    /**
     * Provedores de IA oferecidos no painel — ESPELHO da lista fechada do backend.
     *
     * ⚠️ A ORIGEM DESTA LISTA É `generator.PROVEDORES` (backend-python/app/
     * generator.py:656), e não este arquivo. Aqui é cópia declarada, e a decisão
     * de que fosse cópia foi tomada em 2026-09-07 depois de medir a alternativa:
     * buscar a lista do backend exigiria rota nova (não existe canal de
     * descoberta — o plugin nunca chama /health, e o $backend_ok de
     * page-instance-form.php:551 é só "a URL está preenchida") e seria CIRCULAR,
     * porque esta é justamente a tela onde se configura a URL do backend: numa
     * instalação nova o <select> nasceria vazio. Um fallback local seria
     * obrigatório de todo jeito, ou seja: a rota rebaixaria a cópia a fallback
     * em vez de eliminá-la.
     *
     * A regra que o backend já contratou em generator.py:621-624 —
     * ACRESCENTAR PROVEDOR = uma linha na tabela de lá (a coluna `construtor`
     * inclusive) + o parâmetro de header nas 6 rotas de main.py + esta whitelist.
     * Os três, ou o provedor fica selecionável e não funciona.
     *
     * As três colunas, e de onde cada uma vem:
     *
     * - `rotulo` — só UI. Espelha o `rotulo` da tabela do backend.
     * - `option_chave` — a única que o backend **não conhece nem poderia**: qual
     *   option do WordPress guarda a chave de qual provedor é conhecimento do
     *   plugin. `generator.PROVEDORES` fala de `env_chave`/`campo_chave`, que são
     *   nomes do Settings do Python.
     * - `header_chave` — ⚠️ **ESPELHO EXATO** de `header_chave` em
     *   `generator.py:661` e `:669`, e a string tem de casar com o nome do
     *   parâmetro declarado nas 6 rotas de `main.py`. **Divergir aqui não levanta
     *   erro:** o backend recebe um header que não declarou, ignora, e a cascata
     *   cai para o `.env` — a chave do painel deixa de governar a geração **em
     *   silêncio**, que é exatamente o defeito que a fase 0 do H1 corrigiu.
     *
     * `header_chave` entrou no **B6**, quando os quatro `wp_remote_post` passaram
     * a mandar a chave do provedor ativo. Sem ela, cada um dos quatro precisaria
     * de um `if ( 'deepseek' === … )` só para escolher o nome do header — o mesmo
     * motivo pelo qual `_resolve_api_key()` (`main.py:282`) resolve header e campo
     * pela tabela em vez de comparar slug nas seis rotas.
     */
    const PROVEDORES = array(
        'gemini'   => array(
            'rotulo'       => 'Gemini',
            'option_chave' => 'newsgame_gemini_api_key',
            'header_chave' => 'X-Gemini-Api-Key',
        ),
        'deepseek' => array(
            'rotulo'       => 'DeepSeek',
            'option_chave' => 'newsgame_deepseek_api_key',
            'header_chave' => 'X-DeepSeek-Api-Key',
        ),
    );

    /**
     * Provedor usado quando não há valor válido salvo. Espelha
     * `generator.PROVEDOR_DEFAULT` (generator.py:625).
     *
     * ⚠️ NÃO é o valor de queda de um POST inválido — ver sanitize_provedor().
     */
    const PROVEDOR_DEFAULT = 'gemini';

    /**
     * Options que nascem com `autoload` desligado.
     *
     * `register_setting()` NÃO aceita autoload (conferido nos $defaults do WP 7.1
     * local: type, group, label, description, sanitize_callback, show_in_rest),
     * então o ajuste vai por hook — ver fixar_autoload().
     *
     * ⚠️ ASSIMETRIA DELIBERADA: `newsgame_gemini_api_key` fica em `auto`, como
     * está hoje no banco. Mexer no autoload de uma option de produção já em uso
     * é decisão própria e ficou FORA deste escopo, por decisão de 2026-09-07;
     * anotado como item futuro na FLAG 011.
     *
     * E não confundir com criptografia: isto é REDUÇÃO DE SUPERFÍCIE (o segredo
     * deixa de entrar no cache de options carregado em todo request, público
     * incluído), não proteção do valor. A chave segue em texto claro em
     * wp_options e segue saindo no fonte HTML desta própria tela. FLAG 011.
     */
    const OPTIONS_AUTOLOAD_OFF = array(
        'newsgame_llm_provider',
        'newsgame_deepseek_api_key',
    );

    /** Hook suffix da tela de configurações — usado para enfileirar só nela */
    private static $hook_suffix = '';

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_init',            array( __CLASS__, 'register_fields' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        // Um hook por option, e não um 'added_option' genérico: o hook nomeado
        // dispara EXATAMENTE UMA VEZ na vida de cada option — no momento em que
        // ela é criada —, então em regime o custo é zero. A alternativa
        // (wp_set_option_autoload no admin_init) cobraria um SELECT por página do
        // admin para sempre; medido na implementação de
        // wp_set_option_autoload_values() (wp-includes/option.php:397-471), que
        // faz um SELECT de checagem e só emite UPDATE no que difere.
        foreach ( self::OPTIONS_AUTOLOAD_OFF as $option ) {
            add_action( 'add_option_' . $option, array( __CLASS__, 'fixar_autoload' ) );
        }
    }

    /**
     * Desliga o autoload de uma option recém-criada.
     *
     * Chamada pelo hook `add_option_{$option}`, que já roda DEPOIS da inserção —
     * o que importa porque wp_set_option_autoload_values() filtra por
     * `option_name IN (...)` e portanto só alcança option que já existe. Chamar
     * antes da primeira gravação não pré-configuraria nada, e é por isso que
     * add_option() na ativação do plugin não resolveria: numa instalação que já
     * roda, a option nasce no primeiro save da tela, não na ativação (e subir o
     * XAMPP não reativa plugin).
     *
     * `function_exists` porque wp_set_option_autoload() é do WP 6.4 e o plugin se
     * declara compatível com WP 6.x inteiro. Em WP mais antigo a option
     * simplesmente fica em `auto` — degradação, não erro fatal.
     *
     * A guarda de lista existe para o caso de o método ser chamado direto: o hook
     * é nomeado e já garante a origem, mas um callback público que aceita
     * qualquer nome de option e mexe no banco não deve confiar só nisso.
     *
     * @param string $option Nome da option, vindo do hook.
     */
    public static function fixar_autoload( $option ) {
        if ( ! in_array( $option, self::OPTIONS_AUTOLOAD_OFF, true ) ) {
            return;
        }
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            wp_set_option_autoload( $option, false );
        }
    }

    public static function register_menu() {
        self::$hook_suffix = add_menu_page(
            'News-Game',
            'News-Game',
            'manage_options',
            'newsgame',
            array( __CLASS__, 'render_page' ),
            'dashicons-editor-table',
            58
        );
        add_submenu_page(
            'newsgame',
            'Configurações — News-Game',
            'Configurações',
            'manage_options',
            'newsgame',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Enfileira o JS da tela de configurações — A3, FLAG 007 passo 3.
     *
     * A comparação é com o hook devolvido pelo add_menu_page(), e não com uma
     * string escrita à mão ('toplevel_page_newsgame'): renomear o slug do menu
     * passa a quebrar o enfileiramento de forma visível, e não em silêncio.
     *
     * Os dados vão por wp_localize_script, e não por JS montado em PHP: o JSON é
     * gerado e escapado pelo WordPress, o que dispensa o esc_js() que existia nas
     * quatro linhas de action/nonce do bloco inline.
     *
     * ⚠️ WP_Scripts::localize() aplica `(string)` em todo escalar de topo. Aqui os
     * quatro valores já são string, então não há conversão a compensar no JS.
     */
    public static function enqueue_assets( $hook ) {
        if ( ! self::$hook_suffix || $hook !== self::$hook_suffix ) {
            return;
        }

        wp_enqueue_script(
            'newsgame-admin-settings',
            NEWSGAME_URL . 'admin/js/newsgame-admin-settings.js',
            array(),
            NEWSGAME_VERSION,
            true  // footer
        );

        wp_localize_script( 'newsgame-admin-settings', 'ngSettingsCfg', array(
            'actionSync' => Newsgame_Ingestor::AJAX_ACTION,
            'nonceSync'  => wp_create_nonce( Newsgame_Ingestor::NONCE_KEY ),
            'actionPush' => Newsgame_Generator::AJAX_PUSH,
            'noncePush'  => wp_create_nonce( Newsgame_Generator::NONCE_PUSH ),
        ) );
    }

    public static function register_fields() {
        register_setting( 'newsgame_settings', 'newsgame_llm_provider', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_provedor' ),
        ) );
        register_setting( 'newsgame_settings', 'newsgame_gemini_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_deepseek_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_organization_name', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'News-Game',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_backend_url', array(
            'sanitize_callback' => 'esc_url_raw',
        ) );
        register_setting( 'newsgame_settings', 'newsgame_backend_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    /* ── Provedor de IA ─────────────────────────────────────────── */

    /**
     * Valida um slug de provedor contra a whitelist. Devolve '' se não bater.
     *
     * Espelha `generator.normalizar_provedor()`: sanitize_key() baixa a caixa e
     * corta o que não é [a-z0-9_-], então `" Gemini "` vira `gemini` nos dois
     * lados da integração.
     *
     * `is_scalar` antes do cast pelo mesmo motivo registrado em sanitize_skin():
     * o valor vem do $_POST, e `newsgame_llm_provider[]=x` chega como array —
     * `(string) array()` emitiria warning e produziria "Array".
     *
     * String vazia é RETORNO ESPERADO e significa "não é provedor conhecido". Quem
     * chama decide o que fazer com isso, e as duas decisões são diferentes:
     * sanitize_provedor() na escrita, llm_provider() na leitura.
     *
     * @param  mixed $value Valor cru.
     * @return string Slug válido, ou '' se não estiver na whitelist.
     */
    private static function provedor_valido( $value ) {
        $slug = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

        return isset( self::PROVEDORES[ $slug ] ) ? $slug : '';
    }

    /**
     * sanitize_callback de `newsgame_llm_provider` — caminho de ESCRITA.
     *
     * ⚠️ VALOR INVÁLIDO CAI PARA O ATUAL SALVO, NÃO PARA O DEFAULT, e a diferença
     * de política em relação a sanitize_skin() é deliberada: skin inválida cai
     * para 'default' porque o custo é degradação visual, enquanto provedor
     * inválido caindo para 'gemini' em silêncio mandaria a geração para o
     * provedor errado sem sintoma na tela. Preservar o salvo é o que não mascara
     * erro de configuração — mesma intenção do `normalizar_provedor()` do
     * backend, que levanta ValueError em vez de escolher por conta.
     *
     * ⚠️ E É AQUI QUE MORA A DEFESA CONTRA A FAMÍLIA DO C11. Campo AUSENTE do
     * POST não é ignorado pelo WordPress: wp-admin/options.php:336-345 percorre
     * as options REGISTRADAS no grupo (não as recebidas), inicializa
     * `$value = null` e chama update_option() de qualquer jeito. Com um callback
     * do tipo sanitize_text_field(), esse null viraria '' e a option seria
     * ZERADA por um save que nem tocou no campo. Aqui o null cai em
     * provedor_valido() → '' → valor atual preservado.
     *
     * A proteção PRINCIPAL, porém, é do lado do formulário: o <select> é gerado
     * em loop sobre a whitelist e o `selected` sai de llm_provider(), ou seja do
     * banco — nunca comparado contra um default assumido. Salvar a tela sem
     * tocar no seletor reenvia o valor atual e a escrita é idempotente. Este
     * callback é a rede para o que o formulário não cobre: POST forjado, campo
     * `disabled`, ou uma futura exibição condicional do seletor.
     *
     * @param  mixed $value Valor cru vindo do $_POST (pode ser null).
     * @return string Slug de provedor sempre válido.
     */
    public static function sanitize_provedor( $value ) {
        $novo = self::provedor_valido( $value );
        if ( '' !== $novo ) {
            return $novo;
        }

        // get_option() aqui devolve o valor ANTERIOR: o filtro sanitize_option_*
        // roda dentro de update_option(), antes da escrita. Sem recursão — o
        // filtro que nos chamou é sanitize_option_*, não option_*.
        $atual = self::provedor_valido( get_option( 'newsgame_llm_provider', '' ) );

        return '' !== $atual ? $atual : self::PROVEDOR_DEFAULT;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        require NEWSGAME_DIR . 'admin/views/page-settings.php';
    }

    /* Acessores estáticos — use em qualquer ponto do plugin */

    public static function gemini_key() {
        return (string) get_option( 'newsgame_gemini_api_key', '' );
    }

    public static function deepseek_key() {
        return (string) get_option( 'newsgame_deepseek_api_key', '' );
    }

    /**
     * Provedor de IA ativo — caminho de LEITURA.
     *
     * Normaliza na leitura também, e não só na escrita, pelo mesmo motivo que
     * tornou sanitize_skin() pública: linha gravada por SQL à mão, ou salva
     * antes de uma entrada sair da whitelist, chega aqui com um valor que não
     * existe mais. Quem monta header ou caminho a partir do provedor passa por
     * este método, e nunca por get_option() cru.
     *
     * ⚠️ AQUI a queda É para PROVEDOR_DEFAULT, ao contrário de
     * sanitize_provedor(): na leitura não existe "valor anterior" a preservar, e
     * devolver '' faria o chamador montar requisição sem provedor.
     *
     * @return string Slug de provedor sempre válido.
     */
    public static function llm_provider() {
        $slug = self::provedor_valido( get_option( 'newsgame_llm_provider', '' ) );

        return '' !== $slug ? $slug : self::PROVEDOR_DEFAULT;
    }

    /**
     * Chave de API do provedor ATIVO, resolvida pela tabela.
     *
     * Este é o caso "a chave que vale para a requisição que estou montando
     * agora", em oposição a "a chave de um provedor específico".
     *
     * ⚠️ E O B6 DEIXOU `gemini_key()` E `deepseek_key()` SEM NENHUM CHAMADOR.
     * O `gemini_key()` era usado exatamente nos quatro `wp_remote_post` que
     * passaram a chamar `provider_headers()`; o `deepseek_key()` nasceu sem
     * chamador no B5. A tela **não** os usa — `page-settings.php` lê as duas
     * options por `get_option()` direto.
     *
     * ✅ **DECISÃO TOMADA EM 07/09: MANTER OS DOIS.** Não é pendência e não é
     * esquecimento. São acessores públicos da classe, e removê-los seria churn
     * sem ganho — quando algo precisar ler a chave de um provedor ESPECÍFICO,
     * fora do fluxo "provedor ativo" que este método atende, eles já existem
     * prontos. Registrado aqui para que "sem chamador" não seja redescoberto
     * como novidade nem tratado como código morto a limpar.
     *
     * @return string Chave do provedor ativo, ou '' se não configurada.
     */
    public static function provider_key() {
        $meta = self::PROVEDORES[ self::llm_provider() ];

        return (string) get_option( $meta['option_chave'], '' );
    }

    /**
     * Headers de provedor para as requisições ao backend — o par do
     * `_resolve_api_key()` do lado Python.
     *
     * Devolve `X-LLM-Provider` mais o header de chave do provedor ativo, com o
     * NOME do header saindo da tabela. É o que permite aos quatro
     * `wp_remote_post` não terem uma linha de condicional: acrescentar provedor
     * é uma entrada em `PROVEDORES`, e nenhum dos quatro arquivos é tocado.
     *
     * ⚠️ MANDA UM SÓ HEADER DE CHAVE, o do provedor ativo — não os dois. A
     * alternativa (mandar `X-Gemini-Api-Key` e `X-DeepSeek-Api-Key` sempre, e
     * deixar o `X-LLM-Provider` decidir qual o backend lê) é mais curta e foi
     * DESCARTADA no B6: poria dois segredos em toda requisição para o backend
     * usar um. Mesma invariante do A1/FLAG 003 — segredo no menor número de
     * lugares possível.
     *
     * ⚠️ O PROVEDOR VIAJA SÓ EM HEADER, nunca no corpo. É decisão do lado Python
     * (ver a nota de `_resolve_provedor()`, `main.py:245`) e o plugin a respeita:
     * `models.py` tem campo de chave para os dois provedores, mas **nenhum campo
     * de provedor**.
     *
     * Chave vazia é passada como header vazio, de propósito, e não omitida: o
     * backend trata `''` como "degrau não preenchido" e desce a cascata para o
     * corpo e depois para o `.env`. Omitir o header daria o mesmo resultado com
     * mais código.
     *
     * @return array<string,string> Headers prontos para `array_merge()`.
     */
    public static function provider_headers() {
        $slug = self::llm_provider();
        $meta = self::PROVEDORES[ $slug ];

        return array(
            'X-LLM-Provider'        => $slug,
            $meta['header_chave']   => self::provider_key(),
        );
    }

    public static function org_name() {
        return (string) get_option( 'newsgame_organization_name', 'News-Game' );
    }

    public static function backend_url() {
        return (string) get_option( 'newsgame_backend_url', '' );
    }

    /* ── Throttle de custo — protege a cota da chave Gemini ─────── */

    /**
     * Contador de janela fixa por usuário, incrementado atomicamente no banco.
     *
     * Protege contra erro operacional (clique duplo numa UI que parece
     * travada, loop de JS com bug), não contra ataque: as rotas protegidas
     * já exigem editor autenticado com manage_options. Por isso a chave é o
     * usuário, e não o IP — dois editores atrás do mesmo NAT não disputam
     * a mesma cota.
     *
     * A implementação anterior usava get_transient() + set_transient(), o que
     * é um read-modify-write: N requisições simultâneas liam o mesmo contador
     * antes de qualquer uma escrever, e todas passavam. Medido com 12 chamadas
     * paralelas em /analyze-domain — nenhuma bloqueada. Justamente o cenário
     * que motiva o throttle (loop de JS sem await), então o incremento agora
     * acontece dentro de uma única instrução SQL.
     *
     * A janela é fixa e alinhada ao minuto do relógio (floor(time()/60) entra
     * na chave), não deslizante — permite rajada de até 2x na virada. Suficiente
     * para erro operacional; não serviria contra ataque.
     *
     * Sob rajada simultânea o contador lido de volta pode já incluir chamadas
     * irmãs, bloqueando algumas a mais do que o limite estrito. Para uma trava
     * de custo, errar para o lado de bloquear é o lado certo. Em uso sequencial
     * — o caso normal — a contagem é exata.
     *
     * @param  string $bucket        Identificador da rota (ex: 'generate_one').
     * @param  int    $limit_per_min Máximo de chamadas por minuto por usuário.
     * @return bool   true se o limite foi excedido — o chamador deve recusar.
     */
    public static function rate_limited( $bucket, $limit_per_min ) {
        global $wpdb;

        $prefixo = 'newsgame_rl_' . sanitize_key( $bucket ) . '_' . get_current_user_id() . '_';
        $chave   = $prefixo . (int) floor( time() / 60 );

        // Atômico: o MySQL resolve o incremento dentro da própria instrução,
        // então não há janela entre ler e escrever. Depende do índice único
        // em wp_options.option_name, que faz parte do schema do WordPress.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $chave
        ) );

        // rows_affected é 1 quando a linha foi criada — ou seja, primeira
        // chamada desta janela. É o momento barato de varrer as janelas
        // velhas deste mesmo usuário/rota, sem cron nem coleta probabilística.
        if ( 1 === $wpdb->rows_affected ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
                $wpdb->esc_like( $prefixo ) . '%',
                $chave
            ) );
        }

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $chave
        ) );

        return $count > (int) $limit_per_min;
    }
}
