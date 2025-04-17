
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { PowerIcon, WifiIcon, GlobeIcon, ServerIcon, AlertTriangleIcon, CheckCircleIcon } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

interface ConfigurationProps {
  deviceId?: string;
}

export function DeviceConfiguration({ deviceId }: ConfigurationProps) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [connectionType, setConnectionType] = useState("DHCP");
  const [currentSettings, setCurrentSettings] = useState<any>(null);
  const [connectionStatus, setConnectionStatus] = useState<{
    status: 'unknown' | 'success' | 'error';
    message: string;
    details?: string;
    lastChecked?: string;
  }>({
    status: 'unknown',
    message: 'Connection status unknown'
  });

  // TR069 form state
  const [tr069Config, setTr069Config] = useState({
    username: "",
    password: "",
    informInterval: 300
  });

  // WiFi form state
  const [wifiConfig, setWifiConfig] = useState({
    ssid: "",
    password: "",
    security: "WPA2-PSK"
  });

  // WAN form state
  const [wanConfig, setWanConfig] = useState({
    connectionType: "DHCP",
    pppoeUsername: "",
    pppoePassword: "",
    staticIp: "",
    subnetMask: "",
    gateway: "",
    dnsServers: ""
  });

  // Fetch current device settings on component mount
  useEffect(() => {
    if (deviceId) {
      fetchDeviceSettings();
    }
  }, [deviceId]);

  const fetchDeviceSettings = async () => {
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'get_settings');
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success && result.settings) {
        setCurrentSettings(result.settings);
        
        // Update form states with current settings
        if (result.settings.ssid) {
          setWifiConfig(prev => ({
            ...prev,
            ssid: result.settings.ssid,
            password: '' // Don't populate password for security
          }));
        }
        
        if (result.settings.ip_address) {
          setWanConfig(prev => ({
            ...prev,
            staticIp: result.settings.ip_address,
            gateway: result.settings.gateway || ''
          }));
        }

        // Check connection status if available
        if (result.connection_status) {
          setConnectionStatus({
            status: result.connection_status.success ? 'success' : 'error',
            message: result.connection_status.message || 'Connection status retrieved',
            details: result.connection_status.details || undefined,
            lastChecked: new Date().toLocaleString()
          });
        }
        
        console.log("Fetched current device settings:", result.settings);
      }
    } catch (error) {
      console.error("Error fetching device settings:", error);
    }
  };

  const checkTR069Connection = async () => {
    setIsSubmitting(true);
    
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'check_connection');
      
      toast.loading("Checking TR-069 connection...");
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        setConnectionStatus({
          status: 'success',
          message: result.message || 'Connection successful',
          lastChecked: new Date().toLocaleString()
        });
        toast.success(result.message || "Connection test successful");
      } else {
        setConnectionStatus({
          status: 'error',
          message: result.message || 'Connection failed',
          details: result.details || 'No additional details available',
          lastChecked: new Date().toLocaleString()
        });
        toast.error(result.message || "Connection test failed");
      }
      
      console.log("Connection check result:", result);
    } catch (error) {
      console.error("Error checking connection:", error);
      toast.error("Failed to check connection");
      setConnectionStatus({
        status: 'error',
        message: 'Error checking connection',
        lastChecked: new Date().toLocaleString()
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleTr069Change = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setTr069Config({
      ...tr069Config,
      [name]: value
    });
  };

  const handleWifiChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setWifiConfig({
      ...wifiConfig,
      [name]: value
    });
  };

  const handleWanChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setWanConfig({
      ...wanConfig,
      [name]: value
    });
  };

  const handleSecurityChange = (value: string) => {
    setWifiConfig({
      ...wifiConfig,
      security: value
    });
  };

  const handleConnectionTypeChange = (value: string) => {
    setConnectionType(value);
    setWanConfig({
      ...wanConfig,
      connectionType: value
    });
  };

  const updateTr069Config = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    try {
      // Create form data for the API request
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'tr069');
      formData.append('username', tr069Config.username);
      formData.append('password', tr069Config.password);
      formData.append('inform_interval', tr069Config.informInterval.toString());
      
      // Log configuration change intent
      console.log("Updating TR069 Configuration:", tr069Config);
      
      // Make API call to update TR069 config
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        toast.success("TR069 configuration updated successfully");
        
        // Update connection status if provided
        if (result.connection_status) {
          setConnectionStatus({
            status: result.connection_status.success ? 'success' : 'error',
            message: result.connection_status.message || 'Connection status updated',
            details: result.connection_status.details || undefined,
            lastChecked: new Date().toLocaleString()
          });
        }
      } else {
        toast.error(result.message || "Failed to update TR069 configuration");
      }
    } catch (error) {
      toast.error("Failed to update TR069 configuration");
      console.error("Error updating TR069 configuration:", error);
    } finally {
      setIsSubmitting(false);
    }
  };

  const updateWifiConfig = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    try {
      // Log configuration change intent
      console.log("Updating WiFi Configuration:", {
        ssid: wifiConfig.ssid,
        passwordLength: wifiConfig.password ? wifiConfig.password.length : 0,
        security: wifiConfig.security
      });
      
      // Create form data for the API request
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'wifi');
      formData.append('ssid', wifiConfig.ssid);
      formData.append('password', wifiConfig.password);
      formData.append('security', wifiConfig.security);
      
      toast.loading("Sending WiFi configuration to device...");
      
      // Make actual API call to update WiFi config
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        console.log("WiFi configuration update successful:", result);
        toast.success(result.message || "WiFi configuration updated successfully");
        
        // Update local state if needed
        if (currentSettings) {
          setCurrentSettings({
            ...currentSettings,
            ssid: wifiConfig.ssid
          });
        }
        
        // Update connection status if provided
        if (result.connection_status) {
          setConnectionStatus({
            status: result.connection_status.success ? 'success' : 'error',
            message: result.connection_status.message || 'Connection attempted',
            details: result.connection_status.details || undefined,
            lastChecked: new Date().toLocaleString()
          });
        }
      } else {
        console.error("WiFi configuration update failed:", result);
        toast.error(result.message || "Failed to update WiFi configuration");
        
        if (result.connection_status) {
          setConnectionStatus({
            status: 'error',
            message: result.connection_status.message || 'Connection failed',
            details: result.connection_status.details || undefined,
            lastChecked: new Date().toLocaleString()
          });
        }
      }
    } catch (error) {
      toast.error("Failed to update WiFi configuration");
      console.error("Error updating WiFi configuration:", error);
    } finally {
      setIsSubmitting(false);
    }
  };

  const updateWanConfig = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    
    try {
      // Log configuration change intent
      console.log("Updating WAN Configuration:", {
        connectionType: wanConfig.connectionType,
        staticIp: wanConfig.staticIp,
        gateway: wanConfig.gateway,
        // Other WAN settings
      });
      
      // Create form data for the API request
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'wan');
      formData.append('ip_address', wanConfig.staticIp);
      formData.append('gateway', wanConfig.gateway);
      
      if (wanConfig.connectionType === 'PPPoE') {
        formData.append('pppoe_username', wanConfig.pppoeUsername);
        formData.append('pppoe_password', wanConfig.pppoePassword);
      } else if (wanConfig.connectionType === 'Static') {
        formData.append('subnet_mask', wanConfig.subnetMask);
        formData.append('dns_servers', wanConfig.dnsServers);
      }
      
      // Make API call to update WAN config
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        console.log("WAN configuration update successful:", result);
        toast.success(result.message || "WAN configuration updated successfully");
        
        // Update local state if needed
        if (currentSettings) {
          setCurrentSettings({
            ...currentSettings,
            ip_address: wanConfig.staticIp,
            gateway: wanConfig.gateway
          });
        }
      } else {
        console.error("WAN configuration update failed:", result);
        toast.error(result.message || "Failed to update WAN configuration");
      }
    } catch (error) {
      toast.error("Failed to update WAN configuration");
      console.error("Error updating WAN configuration:", error);
    } finally {
      setIsSubmitting(false);
    }
  };

  const rebootDevice = async () => {
    if (!window.confirm("Are you sure you want to reboot the device? All connections will be temporarily disrupted.")) {
      return;
    }
    
    setIsSubmitting(true);
    
    try {
      // Log reboot intent
      console.log("Initiating device reboot for device ID:", deviceId);
      
      // Create form data for the API request
      const formData = new FormData();
      formData.append('device_id', deviceId || '');
      formData.append('action', 'reboot');
      
      // Make API call to reboot device
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        console.log("Device reboot command successful:", result);
        toast.success(result.message || "Reboot command sent to device");
      } else {
        console.error("Device reboot failed:", result);
        toast.error(result.message || "Failed to reboot device");
      }
    } catch (error) {
      toast.error("Failed to reboot device");
      console.error("Error rebooting device:", error);
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="space-y-6">
      {connectionStatus.status === 'error' && (
        <Alert variant="destructive" className="mb-4">
          <AlertTriangleIcon className="h-4 w-4" />
          <AlertTitle>TR-069 Connection Problem</AlertTitle>
          <AlertDescription>
            <p>{connectionStatus.message}</p>
            {connectionStatus.details && (
              <details className="mt-2 text-sm">
                <summary className="cursor-pointer font-medium">View technical details</summary>
                <p className="mt-2 whitespace-pre-wrap">{connectionStatus.details}</p>
              </details>
            )}
            <div className="mt-4">
              <Button 
                size="sm" 
                variant="outline" 
                onClick={checkTR069Connection} 
                disabled={isSubmitting}
              >
                Check Connection
              </Button>
            </div>
          </AlertDescription>
        </Alert>
      )}

      <Tabs defaultValue="tr069" className="w-full">
        <TabsList className="grid w-full grid-cols-4">
          <TabsTrigger value="tr069">
            <ServerIcon className="h-4 w-4 mr-2" />
            TR069
          </TabsTrigger>
          <TabsTrigger value="wifi">
            <WifiIcon className="h-4 w-4 mr-2" />
            WiFi
          </TabsTrigger>
          <TabsTrigger value="wan">
            <GlobeIcon className="h-4 w-4 mr-2" />
            WAN
          </TabsTrigger>
          <TabsTrigger value="reboot">
            <PowerIcon className="h-4 w-4 mr-2" />
            Reboot
          </TabsTrigger>
        </TabsList>
        
        {/* TR069 Configuration Tab */}
        <TabsContent value="tr069" className="space-y-4">
          <div className="bg-white p-6 rounded-lg shadow-sm">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium">TR069 Configuration</h3>
              <div className="flex items-center text-sm">
                {connectionStatus.status === 'success' ? (
                  <span className="text-green-600 flex items-center">
                    <CheckCircleIcon className="h-4 w-4 mr-1" /> Connected
                  </span>
                ) : connectionStatus.status === 'error' ? (
                  <span className="text-red-600 flex items-center">
                    <AlertTriangleIcon className="h-4 w-4 mr-1" /> Connection Issue
                  </span>
                ) : (
                  <span className="text-gray-500">Status Unknown</span>
                )}
                {connectionStatus.lastChecked && (
                  <span className="ml-2 text-gray-500">
                    (Last checked: {connectionStatus.lastChecked})
                  </span>
                )}
              </div>
            </div>

            <form onSubmit={updateTr069Config} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="username">TR069 Username</Label>
                <Input 
                  id="username"
                  name="username"
                  value={tr069Config.username}
                  onChange={handleTr069Change}
                  placeholder="Enter TR069 username"
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="password">TR069 Password</Label>
                <Input 
                  id="password"
                  name="password"
                  type="password"
                  value={tr069Config.password}
                  onChange={handleTr069Change}
                  placeholder="Enter TR069 password"
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="informInterval">Inform Interval (seconds)</Label>
                <Input 
                  id="informInterval"
                  name="informInterval"
                  type="number"
                  min="60"
                  step="60"
                  value={tr069Config.informInterval}
                  onChange={handleTr069Change}
                />
                <p className="text-sm text-gray-500">Minimum 60 seconds recommended</p>
              </div>
              
              <div className="flex space-x-2">
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Updating..." : "Update TR069 Configuration"}
                </Button>
                <Button 
                  type="button" 
                  variant="outline" 
                  onClick={checkTR069Connection} 
                  disabled={isSubmitting}
                >
                  Test Connection
                </Button>
              </div>
            </form>
          </div>
        </TabsContent>
        
        {/* WiFi Configuration Tab */}
        <TabsContent value="wifi" className="space-y-4">
          <div className="bg-white p-6 rounded-lg shadow-sm">
            <h3 className="text-lg font-medium mb-4">WiFi Configuration</h3>
            <form onSubmit={updateWifiConfig} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="ssid">WiFi Network Name (SSID)</Label>
                <Input 
                  id="ssid"
                  name="ssid"
                  value={wifiConfig.ssid}
                  onChange={handleWifiChange}
                  placeholder="Enter WiFi name"
                />
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="password">WiFi Password</Label>
                <Input 
                  id="password"
                  name="password"
                  type="password"
                  value={wifiConfig.password}
                  onChange={handleWifiChange}
                  placeholder="Enter WiFi password"
                />
                <p className="text-sm text-gray-500">Minimum 8 characters recommended</p>
              </div>
              
              <div className="space-y-2">
                <Label htmlFor="security">Security Type</Label>
                <Select 
                  value={wifiConfig.security} 
                  onValueChange={handleSecurityChange}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder="Select security type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="WPA2-PSK">WPA2-PSK (Recommended)</SelectItem>
                    <SelectItem value="WPA-PSK">WPA-PSK</SelectItem>
                    <SelectItem value="WPA3-PSK">WPA3-PSK</SelectItem>
                    <SelectItem value="WEP">WEP (Not Recommended)</SelectItem>
                    <SelectItem value="NONE">None (Unsecured)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? "Updating..." : "Update WiFi Configuration"}
              </Button>

              {connectionStatus.status === 'error' && (
                <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                  <p>Note: TR-069 connection issues may prevent WiFi settings from being applied.</p>
                  <p>Check the connection status under the TR069 tab.</p>
                </div>
              )}
            </form>
          </div>
        </TabsContent>
        
        {/* WAN Configuration Tab */}
        <TabsContent value="wan" className="space-y-4">
          <div className="bg-white p-6 rounded-lg shadow-sm">
            <h3 className="text-lg font-medium mb-4">WAN Configuration</h3>
            <form onSubmit={updateWanConfig} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="connectionType">Connection Type</Label>
                <Select 
                  value={connectionType} 
                  onValueChange={handleConnectionTypeChange}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder="Select connection type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="DHCP">DHCP (Automatic)</SelectItem>
                    <SelectItem value="PPPoE">PPPoE</SelectItem>
                    <SelectItem value="Static">Static IP</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              
              {connectionType === "PPPoE" && (
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="pppoeUsername">PPPoE Username</Label>
                    <Input 
                      id="pppoeUsername"
                      name="pppoeUsername"
                      value={wanConfig.pppoeUsername}
                      onChange={handleWanChange}
                      placeholder="Enter username provided by ISP"
                    />
                  </div>
                  
                  <div className="space-y-2">
                    <Label htmlFor="pppoePassword">PPPoE Password</Label>
                    <Input 
                      id="pppoePassword"
                      name="pppoePassword"
                      type="password"
                      value={wanConfig.pppoePassword}
                      onChange={handleWanChange}
                      placeholder="Enter password provided by ISP"
                    />
                  </div>
                </div>
              )}
              
              {connectionType === "Static" && (
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="staticIp">IP Address</Label>
                    <Input 
                      id="staticIp"
                      name="staticIp"
                      value={wanConfig.staticIp}
                      onChange={handleWanChange}
                      placeholder="e.g., 192.168.1.100"
                    />
                  </div>
                  
                  <div className="space-y-2">
                    <Label htmlFor="subnetMask">Subnet Mask</Label>
                    <Input 
                      id="subnetMask"
                      name="subnetMask"
                      value={wanConfig.subnetMask}
                      onChange={handleWanChange}
                      placeholder="e.g., 255.255.255.0"
                    />
                  </div>
                  
                  <div className="space-y-2">
                    <Label htmlFor="gateway">Default Gateway</Label>
                    <Input 
                      id="gateway"
                      name="gateway"
                      value={wanConfig.gateway}
                      onChange={handleWanChange}
                      placeholder="e.g., 192.168.1.1"
                    />
                  </div>
                  
                  <div className="space-y-2">
                    <Label htmlFor="dnsServers">DNS Servers</Label>
                    <Input 
                      id="dnsServers"
                      name="dnsServers"
                      value={wanConfig.dnsServers}
                      onChange={handleWanChange}
                      placeholder="e.g., 8.8.8.8, 8.8.4.4"
                    />
                  </div>
                </div>
              )}
              
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? "Updating..." : "Update WAN Configuration"}
              </Button>

              {connectionStatus.status === 'error' && (
                <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                  <p>Note: TR-069 connection issues may prevent WAN settings from being applied.</p>
                  <p>Check the connection status under the TR069 tab.</p>
                </div>
              )}
            </form>
          </div>
        </TabsContent>
        
        {/* Reboot Tab */}
        <TabsContent value="reboot" className="space-y-4">
          <div className="bg-white p-6 rounded-lg shadow-sm">
            <h3 className="text-lg font-medium mb-4">Reboot Device</h3>
            
            <div className="rounded-md bg-yellow-50 p-4 mb-6">
              <div className="flex">
                <div className="flex-shrink-0">
                  <svg className="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                  </svg>
                </div>
                <div className="ml-3">
                  <h3 className="text-sm font-medium text-yellow-800">Attention required</h3>
                  <div className="mt-2 text-sm text-yellow-700">
                    <p>Rebooting the device will interrupt all active connections. This process typically takes 1-2 minutes to complete.</p>
                  </div>
                </div>
              </div>
            </div>
            
            <Button 
              variant="destructive" 
              onClick={rebootDevice}
              disabled={isSubmitting || connectionStatus.status === 'error'}
            >
              {isSubmitting ? "Processing..." : "Reboot Device"}
            </Button>

            {connectionStatus.status === 'error' && (
              <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                <p>TR-069 connection issues may prevent remote reboot from working.</p>
                <p>Fix connection issues before attempting to reboot the device.</p>
              </div>
            )}
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
