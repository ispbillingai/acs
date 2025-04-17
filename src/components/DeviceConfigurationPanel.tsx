import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { 
  Wifi, Globe, PowerOff, AlertTriangle, Lock, HelpCircle, 
  RefreshCw, ChevronDown, ChevronUp, Copy, Terminal, 
  CheckCircle, XCircle, PlayCircle, Server
} from "lucide-react";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');
  const [connectionRequestUsername, setConnectionRequestUsername] = useState('');
  const [connectionRequestPassword, setConnectionRequestPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [configuring, setConfiguring] = useState(false);
  const [connectionRequest, setConnectionRequest] = useState<any>(null);
  const [expandedCommands, setExpandedCommands] = useState(false);
  const [tr069SessionId, setTr069SessionId] = useState<string | null>(null);
  const [selectedPort, setSelectedPort] = useState('30005');
  const [connectionTestStatus, setConnectionTestStatus] = useState<'idle' | 'testing' | 'success' | 'failure'>('idle');
  const [testResults, setTestResults] = useState<string[]>([]);
  const [parameterPath, setParameterPath] = useState('InternetGatewayDevice.LANDevice.1.WLANConfiguration.');
  const [discoveryInProgress, setDiscoveryInProgress] = useState(false);
  const [rebootReason, setRebootReason] = useState('User initiated reboot');
  const [rebootConfirmationOpen, setRebootConfirmationOpen] = useState(false);

  useEffect(() => {
    console.log("DeviceConfigurationPanel mounted with deviceId:", deviceId);
    
    const fetchDeviceSettings = async () => {
      try {
        setLoading(true);
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_settings');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        console.log("Fetched device settings:", result);
        
        if (result.success && result.settings) {
          setWifiSSID(result.settings.ssid || '');
          setWifiPassword(result.settings.password || '');
          setWanIPAddress(result.settings.ip_address || '');
          setWanGateway(result.settings.gateway || '');
          setConnectionRequestUsername(result.settings.connection_request_username || '');
          setConnectionRequestPassword(result.settings.connection_request_password || '');
        } else {
          toast.error("Failed to load device settings");
        }
      } catch (error) {
        console.error('Error fetching device settings:', error);
        toast.error("Error loading device settings");
      } finally {
        setLoading(false);
      }
    };
    
    fetchDeviceSettings();
  }, [deviceId]);

  const makeConfigRequest = async (action: string, data: Record<string, string>) => {
    setConfiguring(true);
    try {
      console.log(`Making ${action} config request with data:`, data);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', action);

      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`${action} config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        if (result.connection_request) {
          setConnectionRequest(result.connection_request);
        }
        
        if (result.tr069_session_id) {
          setTr069SessionId(result.tr069_session_id);
        }
        
        if (action === 'wifi') {
          toast.info(
            <div className="flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
              <div>
                <p className="font-medium">TR-069 Configuration Workflow</p>
                <p className="text-sm">Complete TR-069 session requires these steps:</p>
                <ol className="text-sm list-decimal pl-5 mt-1">
                  <li>Send a connection request (test multiple ports if needed)</li>
                  <li>Wait for device to open a session with ACS (Inform)</li>
                  <li>ACS responds with InformResponse and sends SetParameterValues</li>
                  <li>For Huawei HG8145V5, use PreSharedKey.1.PreSharedKey (not KeyPassphrase)</li>
                  <li>Send Commit command with matching CommandKey</li>
                </ol>
              </div>
            </div>,
            { duration: 15000 }
          );
        }
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in ${action} config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleWiFiUpdate = () => {
    console.log("WiFi update button clicked");
    if (!wifiSSID.trim()) {
      toast.error('WiFi SSID cannot be empty');
      return;
    }
    makeConfigRequest('wifi', { ssid: wifiSSID, password: wifiPassword });
  };

  const handleWANUpdate = () => {
    console.log("WAN update button clicked");
    if (!wanIPAddress.trim()) {
      toast.error('IP Address cannot be empty');
      return;
    }
    makeConfigRequest('wan', { ip_address: wanIPAddress, gateway: wanGateway });
  };

  const handleConnectionRequestUpdate = () => {
    console.log("Connection Request update button clicked");
    if (!connectionRequestUsername.trim()) {
      toast.error('Connection Request Username cannot be empty');
      return;
    }
    if (!connectionRequestPassword.trim()) {
      toast.error('Connection Request Password cannot be empty');
      return;
    }
    makeConfigRequest('connection_request', { 
      username: connectionRequestUsername, 
      password: connectionRequestPassword 
    });
  };

  const handleReboot = () => {
    console.log("Reboot button clicked");
    if (window.confirm(`Are you sure you want to reboot this device? Reason: ${rebootReason}`)) {
      makeConfigRequest('reboot', { reason: rebootReason });
      toast.info(
        <div className="space-y-2">
          <p className="font-medium">Reboot Command Sent</p>
          <p className="text-sm">The device will reboot after the next TR-069 session.</p>
          <p className="text-sm">Devices typically take 1-2 minutes to reboot completely.</p>
        </div>,
        { duration: 10000 }
      );
    }
  };

  const testConnectionRequest = async () => {
    setConnectionTestStatus('testing');
    setTestResults([]);
    
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'test_connection_request');
      formData.append('port', selectedPort);
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }
      
      const result = await response.json();
      console.log('Connection test result:', result);
      
      if (result.success) {
        setConnectionRequest(result.connection_request);
        setConnectionTestStatus('success');
        setTestResults([
          `Testing connection request on port ${selectedPort}`,
          `URL: ${result.connection_request.url}`,
          `Command: ${result.connection_request.command}`
        ]);
        
        toast.success(`Connection request test prepared for port ${selectedPort}`);
      } else {
        setConnectionTestStatus('failure');
        setTestResults([`Error: ${result.message}`]);
        toast.error(result.message || 'Connection test failed');
      }
    } catch (error) {
      console.error('Error testing connection:', error);
      setConnectionTestStatus('failure');
      setTestResults([`Error: ${error instanceof Error ? error.message : 'Unknown error'}`]);
      toast.error('Connection test failed due to server error');
    }
  };

  const discoverParameters = async () => {
    setDiscoveryInProgress(true);
    
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'discover_parameters');
      formData.append('parameter_path', parameterPath);
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }
      
      const result = await response.json();
      console.log('Parameter discovery result:', result);
      
      if (result.success) {
        toast.success('Parameter discovery request generated');
        
        toast.info(
          <div className="space-y-2">
            <p className="font-medium">Parameter Discovery Request</p>
            <p className="text-sm">The request to discover parameters at path:</p>
            <code className="bg-slate-100 px-2 py-1 rounded block text-xs overflow-x-auto whitespace-pre-wrap">{result.parameter_path}</code>
            <p className="text-sm mt-2">This request will be sent during the next TR-069 session. Check the logs for results.</p>
          </div>,
          { duration: 10000 }
        );
      } else {
        toast.error(result.message || 'Parameter discovery failed');
      }
    } catch (error) {
      console.error('Error discovering parameters:', error);
      toast.error('Parameter discovery failed due to server error');
    } finally {
      setDiscoveryInProgress(false);
    }
  };

  const showTR069Info = () => {
    toast.info(
      <div className="space-y-2">
        <p className="font-medium">About TR-069 Configuration</p>
        <p className="text-sm">TR-069 uses a data model with parameters organized in a tree structure.</p>
        <p className="text-sm">For Huawei HG8145V5 devices, we use the TR-098 data model that starts with "InternetGatewayDevice".</p>
        <p className="text-sm">Key Huawei HG8145V5 parameters:</p>
        <ul className="text-sm list-disc pl-5">
          <li>WiFi SSID: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</li>
          <li>WiFi Password: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</li>
          <li>Connection Request URL: InternetGatewayDevice.ManagementServer.ConnectionRequestURL</li>
        </ul>
        <p className="text-sm">Common connection request ports: 30005, 37215, 7547, 4567</p>
      </div>,
      { duration: 15000 }
    );
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success("Command copied to clipboard");
  };

  if (loading) {
    return (
      <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm flex items-center justify-center h-64">
        <div className="text-center">
          <div className="animate-spin h-8 w-8 border-4 border-primary border-t-transparent rounded-full mx-auto mb-4"></div>
          <p className="text-gray-500">Loading device settings...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm">
      <Tabs defaultValue="wifi" className="w-full">
        <TabsList className="w-full">
          <TabsTrigger value="wifi" className="flex-1"><Wifi className="h-4 w-4 mr-2" /> WiFi</TabsTrigger>
          <TabsTrigger value="wan" className="flex-1"><Globe className="h-4 w-4 mr-2" /> WAN</TabsTrigger>
          <TabsTrigger value="tr069" className="flex-1"><Server className="h-4 w-4 mr-2" /> TR-069</TabsTrigger>
          <TabsTrigger value="control" className="flex-1"><PowerOff className="h-4 w-4 mr-2" /> Control</TabsTrigger>
        </TabsList>
        
        <TabsContent value="wifi" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Wifi className="h-5 w-5" />
              WiFi Configuration
              <Button 
                variant="ghost" 
                size="icon" 
                className="h-5 w-5 rounded-full" 
                onClick={showTR069Info}
              >
                <HelpCircle className="h-4 w-4" />
              </Button>
            </h3>
            
            <Alert className="mb-4 bg-amber-50 border-amber-200">
              <AlertTriangle className="h-4 w-4 text-amber-500" />
              <AlertTitle className="text-amber-800">TR-069 Configuration Notice</AlertTitle>
              <AlertDescription className="text-amber-700 text-sm">
                For Huawei HG8145V5 devices, WiFi settings must be updated using the TR-069 protocol with the correct parameter paths.
                After setting values here, use the Connection Request in the TR-069 tab to trigger a session.
              </AlertDescription>
            </Alert>
            
            <div className="space-y-3">
              <Input
                placeholder="WiFi Network Name"
                value={wifiSSID}
                onChange={(e) => setWifiSSID(e.target.value)}
              />
              <Input
                type="password"
                placeholder="WiFi Password"
                value={wifiPassword}
                onChange={(e) => setWifiPassword(e.target.value)}
              />
              <Button 
                onClick={handleWiFiUpdate}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Preparing TR-069 Request...
                  </>
                ) : "Update WiFi"}
              </Button>
              
              <Accordion type="single" collapsible className="w-full">
                <AccordionItem value="tr069-info">
                  <AccordionTrigger className="text-xs text-gray-500">
                    TR-069 Implementation Details for Huawei HG8145V5
                  </AccordionTrigger>
                  <AccordionContent>
                    <div className="text-xs text-gray-600 space-y-2">
                      <p>For Huawei HG8145V5 devices, use these TR-098 parameters:</p>
                      <ul className="list-disc pl-5 space-y-1">
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</code> (2.4GHz WiFi name)</li>
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</code> (WiFi password)</li>
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.ManagementServer.ConnectionRequestURL</code> (Contains the correct connection request port)</li>
                      </ul>
                      <p className="font-medium pt-2">Correct TR-069 workflow:</p>
                      <ol className="list-decimal pl-5 space-y-1">
                        <li>Send connection request to device's ConnectionRequestURL (ports: 30005, 37215, 7547, 4567)</li>
                        <li>Device opens session with ACS by sending Inform</li>
                        <li>ACS responds with InformResponse</li>
                        <li>ACS sends SetParameterValues with correct TR-098 paths</li>
                        <li>Device responds with SetParameterValuesResponse (status 0 = success)</li>
                        <li>ACS sends Commit command with matching CommandKey</li>
                        <li>ACS verifies with GetParameterValues (optional)</li>
                      </ol>
                    </div>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            
            {connectionRequest && (
              <div className="mt-4 p-3 border rounded-md bg-slate-50">
                <h4 className="text-sm font-semibold mb-2 flex items-center">
                  <RefreshCw className="h-4 w-4 mr-1" /> TR-069 Connection Request Details
                </h4>
                <div className="text-xs space-y-1 font-mono bg-slate-100 p-2 rounded">
                  <div className="flex justify-between">
                    <div><span className="text-slate-500">URL:</span> {connectionRequest.url}</div>
                    <button 
                      onClick={() => copyToClipboard(connectionRequest.url)}
                      className="text-blue-500 hover:text-blue-700"
                    >
                      <Copy className="h-3 w-3" />
                    </button>
                  </div>
                  <div><span className="text-slate-500">Username:</span> {connectionRequest.username}</div>
                  <div><span className="text-slate-500">Password:</span> {connectionRequest.password}</div>
                  
                  <div className="pt-2 mt-1 border-t border-slate-200">
                    <div className="flex justify-between items-center">
                      <div className="text-slate-500">Connection Request Command:</div>
                      <button 
                        onClick={() => copyToClipboard(connectionRequest.command)}
                        className="text-blue-500 hover:text-blue-700"
                      >
                        <Copy className="h-3 w-3" />
                      </button>
                    </div>
                    <div className="break-all bg-black text-white p-1 rounded mt-1">
                      {connectionRequest.command}
                    </div>
                  </div>
                  
                  {connectionRequest.alternative_commands && (
                    <div className="pt-2 mt-2 border-t border-slate-200">
                      <div 
                        className="flex justify-between items-center cursor-pointer text-slate-500 hover:text-slate-700"
                        onClick={() => setExpandedCommands(!expandedCommands)}
                      >
                        <span>Alternative connection request URLs to try:</span>
                        {expandedCommands ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                      </div>
                      
                      {expandedCommands && (
                        <div className="mt-2 space-y-2">
                          {connectionRequest.alternative_commands.map((cmd: string, idx: number) => (
                            <div key={idx} className="break-all bg-black text-white p-1 rounded relative">
                              <div className="absolute right-1 top-1">
                                <button 
                                  onClick={() => copyToClipboard(cmd)}
                                  className="text-blue-300 hover:text-blue-100 bg-black bg-opacity-70 rounded p-0.5"
                                >
                                  <Copy className="h-3 w-3" />
                                </button>
                              </div>
                              <div className="pl-1 pr-8">{cmd}</div>
                            </div>
                          ))}
                          <div className="text-xs text-yellow-600 italic">
                            Try these commands if the default one fails. Huawei ONTs typically use connection request ports 30005 or 37215.
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            )}
          </div>
        </TabsContent>
        
        <TabsContent value="wan" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Globe className="h-5 w-5" />
              WAN Configuration
            </h3>
            <div className="space-y-3">
              <Input
                placeholder="IP Address"
                value={wanIPAddress}
                onChange={(e) => setWanIPAddress(e.target.value)}
              />
              <Input
                placeholder="Gateway"
                value={wanGateway}
                onChange={(e) => setWanGateway(e.target.value)}
              />
              <Button 
                onClick={handleWANUpdate}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Updating...
                  </>
                ) : "Update WAN"}
              </Button>
            </div>
          </div>
        </TabsContent>
        
        <TabsContent value="tr069" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Server className="h-5 w-5" />
              TR-069 Management
            </h3>
            
            <Alert className="mb-4">
              <AlertTriangle className="h-4 w-4" />
              <AlertTitle>TR-069 Troubleshooting</AlertTitle>
              <AlertDescription className="text-sm">
                This section helps troubleshoot TR-069 connections for Huawei HG8145V5 devices.
                Use these tools to test different connection ports and discover device parameters.
              </AlertDescription>
            </Alert>
            
            <div className="space-y-6">
              <div className="border rounded-md p-4 space-y-3">
                <h4 className="font-medium flex items-center gap-2">
                  <Terminal className="h-4 w-4" /> 
                  Connection Request Test
                </h4>
                <p className="text-sm text-gray-600">
                  Test connection requests on different ports. Huawei ONTs typically use ports 30005 or 37215.
                </p>
                
                <div className="flex gap-3 flex-wrap">
                  <Select value={selectedPort} onValueChange={setSelectedPort}>
                    <SelectTrigger className="w-[180px]">
                      <SelectValue placeholder="Select port" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="30005">Port 30005 (Huawei)</SelectItem>
                      <SelectItem value="37215">Port 37215 (Huawei)</SelectItem>
                      <SelectItem value="7547">Port 7547 (Standard)</SelectItem>
                      <SelectItem value="4567">Port 4567 (Alternative)</SelectItem>
                    </SelectContent>
                  </Select>
                  
                  <Button 
                    onClick={testConnectionRequest}
                    disabled={connectionTestStatus === 'testing'}
                    variant="outline"
                    className="flex gap-2 items-center"
                  >
                    {connectionTestStatus === 'testing' ? (
                      <>
                        <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full" />
                        Testing...
                      </>
                    ) : (
                      <>
                        <PlayCircle className="h-4 w-4" />
                        Test Connection
                      </>
                    )}
                  </Button>
                </div>
                
                {connectionTestStatus !== 'idle' && (
                  <div className="mt-2 p-2 bg-slate-50 rounded-md border text-sm">
                    <div className="flex items-center gap-2 mb-1">
                      <span className="font-medium">Test Result:</span>
                      {connectionTestStatus === 'success' && <CheckCircle className="h-4 w-4 text-green-500" />}
                      {connectionTestStatus === 'failure' && <XCircle className="h-4 w-4 text-red-500" />}
                      {connectionTestStatus === 'testing' && <RefreshCw className="h-4 w-4 animate-spin" />}
                    </div>
                    <div className="pl-2 space-y-1 text-xs">
                      {testResults.map((result, index) => (
                        <div key={index} className="font-mono">{result}</div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
              
              <div className="border rounded-md p-4 space-y-3">
                <h4 className="font-medium flex items-center gap-2">
                  <RefreshCw className="h-4 w-4" /> 
                  Parameter Discovery
                </h4>
                <p className="text-sm text-gray-600">
                  Discover TR-069 parameters on the device. Use this to verify the correct parameter paths.
                </p>
                
                <div className="space-y-3">
                  <Input
                    placeholder="Parameter Path"
                    value={parameterPath}
                    onChange={(e) => setParameterPath(e.target.value)}
                  />
                  
                  <div className="space-x-2">
                    <Button 
                      onClick={discoverParameters}
                      disabled={discoveryInProgress}
                      variant="outline"
                      className="flex gap-2 items-center"
                    >
                      {discoveryInProgress ? (
                        <>
                          <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full" />
                          Discovering...
                        </>
                      ) : (
                        <>
                          <RefreshCw className="h-4 w-4" />
                          Discover Parameters
                        </>
                      )}
                    </Button>
                    
                    <Button 
                      variant="outline" 
                      onClick={() => setParameterPath('InternetGatewayDevice.LANDevice.1.WLANConfiguration.')}
                      title="Reset to WiFi parameters"
                      size="icon"
                    >
                      <Wifi className="h-4 w-4" />
                    </Button>
                  </div>
                  
                  <div className="text-xs space-y-1">
                    <p className="font-medium">Common parameter paths:</p>
                    <ul className="list-disc pl-5 space-y-1">
                      <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.</code></li>
                      <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.ManagementServer.</code></li>
                      <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.DeviceInfo.</code></li>
                    </ul>
                  </div>
                </div>
              </div>
              
              {tr069SessionId && (
                <div className="border rounded-md p-4 space-y-2">
                  <h4 className="font-medium flex items-center gap-2">
                    <Server className="h-4 w-4" /> 
                    TR-069 Session Information
                  </h4>
                  <div className="text-sm">
                    <div><span className="font-medium">Session ID:</span> {tr069SessionId}</div>
                    <div><span className="font-medium">Status:</span> Pending connection request</div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </TabsContent>
        
        <TabsContent value="control" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Lock className="h-5 w-5" />
              Connection Request Settings
            </h3>
            <div className="space-y-3">
              <Input
                placeholder="Connection Request Username"
                value={connectionRequestUsername}
                onChange={(e) => setConnectionRequestUsername(e.target.value)}
              />
              <Input
                type="password"
                placeholder="Connection Request Password"
                value={connectionRequestPassword}
                onChange={(e) => setConnectionRequestPassword(e.target.value)}
              />
              <Button 
                onClick={handleConnectionRequestUpdate}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Updating...
                  </>
                ) : "Update Connection Settings"}
              </Button>
              <p className="text-xs text-gray-500 mt-1">
                These credentials are used by the ACS to authenticate when making connection requests to the device.
              </p>
            </div>
            
            <div className="mt-6">
              <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
                <PowerOff className="h-5 w-5" />
                Device Control
              </h3>
              
              <div className="space-y-4">
                <Alert className="bg-red-50 border-red-200">
                  <AlertTriangle className="h-4 w-4 text-red-500" />
                  <AlertTitle className="text-red-800">Device Reboot Warning</AlertTitle>
                  <AlertDescription className="text-red-700 text-sm">
                    Rebooting the device will disconnect all users and services. This operation typically takes 1-2 minutes to complete.
                  </AlertDescription>
                </Alert>
                
                <div className="space-y-2">
                  <Label htmlFor="reboot-reason">Reboot Reason (optional)</Label>
                  <Input
                    id="reboot-reason"
                    placeholder="User initiated reboot"
                    value={rebootReason}
                    onChange={(e) => setRebootReason(e.target.value)}
                  />
                  <p className="text-xs text-gray-500">This will be logged in the device's reboot history.</p>
                </div>
                
                <Button 
                  variant="destructive" 
                  onClick={handleReboot}
                  className="w-full md:w-auto"
                  disabled={configuring}
                >
                  {configuring ? (
                    <>
                      <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                      Processing...
                    </>
                  ) : "Reboot Device"}
                </Button>
              </div>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceConfigurationPanel;
