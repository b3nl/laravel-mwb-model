<?php namespace b3nl\MWBModel;

	use Illuminate\Support\ServiceProvider as ProviderBase;

	/**
	 * Service-Provider for the Model Generation.
	 * @author b3nl <github@b3nl.de>
	 * @package b3nl\MWBModel
	 * @version $id$
	 */
	class ServiceProvider extends ProviderBase {
		/**
		 * Indicates if loading of the provider is deferred.
		 * @var bool
		 */
		protected $defer = true;

		/**
		 * Register the service provider.
		 * @return void
		 */
		public function register()
		{
			$this->commands(['b3nl\MWBModel\Console\Commands\MakeMWBModel']);
		} // function

		/**
		 * Get the services provided by the provider.
		 * @return array
		 */
		public function provides()
		{
			return ['b3nl\MWBModel\Console\Commands\MakeMWBModel'];
		} // function
	} // class