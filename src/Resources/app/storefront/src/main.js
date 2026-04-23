import ActPriceLeakGuardPlugin from './price-leak-guard';

const PluginManager = window.PluginManager;
PluginManager.register('ActPriceHideLeakGuard', ActPriceLeakGuardPlugin, 'body');
