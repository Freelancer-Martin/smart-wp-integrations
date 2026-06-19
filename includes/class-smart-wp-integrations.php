<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://freelancermartin.com
 * @since      1.0.0
 *
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/includes
 */

/**
 * Plugina põhiklass — käivitab ja seob kõik komponendid kokku.
 *
 * Rakendab WordPress'i standardset plugin-boilerplate mustrit:
 * - load_dependencies() laeb kõik vajalikud PHP failid
 * - set_locale() registreerib tõlkefunktsioonid
 * - define_admin_hooks() registreerib admin-poolsed hookid
 * - define_public_hooks() registreerib avaliku osa hookid
 * - run() käivitab loader-i, mis seob kõik hookid WordPressiga
 *
 * Loader-muster tähendab, et hookid ei registreerita otse add_action/add_filter
 * kaudu, vaid kogutakse Loader klassi ja registreeritakse kõik korraga run()-is.
 * See võimaldab hookide tsentraliseeritud haldust ja lihtsamat testimist.
 *
 * @since      1.0.0
 * @package    Smart_Wp_Integrations
 * @subpackage Smart_Wp_Integrations/includes
 * @author     Freelancer Martin <freelancermartin1@gmail.com>
 */
class Smart_Wp_Integrations {

	/**
	 * Loader objekt, mis kogub ja registreerib kõik plugina hookid WordPressiga.
	 *
	 * Loader on vahekiht add_action/add_filter ja tegeliku koodiloogika vahel —
	 * see võimaldab kõiki hookisid näha ühes kohas ja lihtsustab silumist.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Smart_Wp_Integrations_Loader    $loader
	 */
	protected $loader;

	/**
	 * Plugina unikaalne identifikaator WordPressi süsteemis.
	 *
	 * Kasutatakse tõlke text domain-ina, skriptide/stiilide handle-ina ning
	 * seadete registreerimisel. Peab ühtima plugin header-i Text Domain väljaga.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name
	 */
	protected $plugin_name;

	/**
	 * Plugina praegune versioonumber.
	 *
	 * Kasutatakse CSS/JS failide versioneerimiseks (cache busting) —
	 * wp_enqueue_script() ja wp_enqueue_style() saavad selle versiooninumbrina.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version
	 */
	protected $version;

	/**
	 * Plugina põhifunktsionaalsuse seadistamine.
	 *
	 * Konstruktor käivitab kogu plugina seadistamise jada — peale konstruktori
	 * täitmist on kõik hookid registreeritud ja plugin töövalmis.
	 * Versioon loetakse SMART_WP_INTEGRATIONS_VERSION konstandist, mis on
	 * defineeritud plugina peafailis.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'SMART_WP_INTEGRATIONS_VERSION' ) ) {
			$this->version = SMART_WP_INTEGRATIONS_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'smart-wp-integrations';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Laeb kõik plugina jaoks vajalikud sõltuvusfailid.
	 *
	 * Failide laadimise järjekord on oluline:
	 * 1. Loader ja i18n (baasklassid)
	 * 2. WC seaded (options-leht)
	 * 3. API klient (LocalApiClient — peab olema enne handler klasse)
	 * 4. Merit handler ja proxy klass
	 * 5. Simplebooks handler (instantseeritakse kohe kui lubatud)
	 * 6. Admin ja public klassid (kasutavad eelnevaid)
	 *
	 * Simplebooks klass instantseeritakse siin (mitte eraldi failis), kuna
	 * konstruktor registreerib WC hooki ja seda tuleb teha plugins_loaded ajal.
	 * Hooki registreerimine hiljem (nt admin_init ajal) jätaks mõned päringud vahele.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-smart-wp-integrations-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-smart-wp-integrations-i18n.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/options/plugin-woocommerce-options.php';

		/**
		 * The class responsible for makeing api request to remote server
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/API/make-api-request.php';

		/**
		 * The class responsible for defining merit aktiva filtering and sending data
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/accounting/merit/class-merit-aktiva-create-invoices.php';

		/**
		 * The class responsible for defining merit aktiva filtering and sending data
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/accounting/merit/class-merit-aktiva-get-data-from-merit-server.php';

		// Simplebooks klassi laadimine ja tingimuslik instantseerimine.
		// Klassifail laetakse alati (et PHP ei annaks "class not found" viga), aga
		// objekt luuakse ainult siis kui Simplebooks on seadetes lubatud.
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/accounting/simplebooks/class-simplebooks-create-invoices.php';
		if ( get_option( 'swi_simplebooks_enable' ) === 'yes' ) {
			new SWI_Simplebooks_Create_Invoices();
		}

		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/accounting/smartaccounts/class-smartaccounts-create-invoices.php';
		if ( get_option( 'swi_smartaccounts_enable' ) === 'yes' ) {
			new SWI_SmartAccounts_Create_Invoices();
		}

		// Kombineeritud integratsioonide kolumn tellimuste nimekirjas
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-swi-order-column.php';
		new SWI_Order_Column();

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-smart-wp-integrations-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-smart-wp-integrations-public.php';

		$this->loader = new Smart_Wp_Integrations_Loader();



	}

	/**
	 * Registreerib tõlke text domain WordPressiga.
	 *
	 * plugins_loaded hook tagab, et tõlked laetakse pärast kõigi pluginate
	 * laadimist, mis on WordPressi soovituslik viis i18n häälestamiseks.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Smart_Wp_Integrations_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Registreerib admin-ala hookid (CSS, JS) loader-i kaudu.
	 *
	 * Admin klassile antakse plugin_name ja version, mida kasutatakse
	 * enqueue funktsioonides CSS/JS failide identifitseerimiseks ja versioonimiseks.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Smart_Wp_Integrations_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	}

	/**
	 * Registreerib avaliku osa hookid (CSS, JS) loader-i kaudu.
	 *
	 * Avaliku osa skriptid laaditakse igal lehel — vajalik kui plugin
	 * peab ka frontend-is midagi tegema (nt checkout integratsioonid).
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Smart_Wp_Integrations_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Käivitab loader-i, mis registreerib kõik kogutud hookid WordPressiga.
	 *
	 * Pärast seda hetkest on kõik add_action() ja add_filter() kutsed tehtud
	 * ja plugin on täielikult töövalmis.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();


	}

	/**
	 * Tagastab plugina unikaalse identifikaatori.
	 *
	 * @since     1.0.0
	 * @return    string    Plugina nimi.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Tagastab viite loader objektile.
	 *
	 * Kasutatakse peamiselt testides, et kontrollida kas hookid on õigesti registreeritud.
	 *
	 * @since     1.0.0
	 * @return    Smart_Wp_Integrations_Loader    Hookide orchestrator.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Tagastab plugina praeguse versiooni.
	 *
	 * @since     1.0.0
	 * @return    string    Versioonumber SemVer formaadis, nt '1.0.0'.
	 */
	public function get_version() {
		return $this->version;
	}

}
