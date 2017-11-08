<?php
/**
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace Tests\Core\Controller;

use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Exceptions\PasswordlessTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OC\Core\Controller\ClientFlowLoginController;
use OCA\OAuth2\Db\AccessTokenMapper;
use OCA\OAuth2\Db\Client;
use OCA\OAuth2\Db\ClientMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Test\TestCase;

class ClientFlowLoginControllerTest extends TestCase {
	/** @var IRequest|\PHPUnit_Framework_MockObject_MockObject */
	private $request;
	/** @var IUserSession|\PHPUnit_Framework_MockObject_MockObject */
	private $userSession;
	/** @var IL10N|\PHPUnit_Framework_MockObject_MockObject */
	private $l10n;
	/** @var Defaults|\PHPUnit_Framework_MockObject_MockObject */
	private $defaults;
	/** @var ISession|\PHPUnit_Framework_MockObject_MockObject */
	private $session;
	/** @var IProvider|\PHPUnit_Framework_MockObject_MockObject */
	private $tokenProvider;
	/** @var ISecureRandom|\PHPUnit_Framework_MockObject_MockObject */
	private $random;
	/** @var IURLGenerator|\PHPUnit_Framework_MockObject_MockObject */
	private $urlGenerator;
	/** @var ClientMapper|\PHPUnit_Framework_MockObject_MockObject */
	private $clientMapper;
	/** @var AccessTokenMapper|\PHPUnit_Framework_MockObject_MockObject */
	private $accessTokenMapper;
	/** @var ICrypto|\PHPUnit_Framework_MockObject_MockObject */
	private $crypto;

	/** @var ClientFlowLoginController */
	private $clientFlowLoginController;

	public function setUp() {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n
			->expects($this->any())
			->method('t')
			->will($this->returnCallback(function($text, $parameters = array()) {
				return vsprintf($text, $parameters);
			}));
		$this->defaults = $this->createMock(Defaults::class);
		$this->session = $this->createMock(ISession::class);
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->clientMapper = $this->createMock(ClientMapper::class);
		$this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
		$this->crypto = $this->createMock(ICrypto::class);

		$this->clientFlowLoginController = new ClientFlowLoginController(
			'core',
			$this->request,
			$this->userSession,
			$this->l10n,
			$this->defaults,
			$this->session,
			$this->tokenProvider,
			$this->random,
			$this->urlGenerator,
			$this->clientMapper,
			$this->accessTokenMapper,
			$this->crypto
		);
	}

	public function testShowAuthPickerPageNoClientOrOauthRequest() {
		$expected = new TemplateResponse(
			'core',
			'error',
			[
				'errors' =>
					[
						[
							'error' => 'Access Forbidden',
							'hint' => 'Invalid request',
						],
					],
			],
			'guest'
		);

		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage());
	}

	public function testShowAuthPickerPageWithOcsHeader() {
		$this->request
			->expects($this->at(0))
			->method('getHeader')
			->with('USER_AGENT')
			->willReturn('Mac OS X Sync Client');
		$this->request
			->expects($this->at(1))
			->method('getHeader')
			->with('OCS-APIREQUEST')
			->willReturn('true');
		$this->random
			->expects($this->once())
			->method('generate')
			->with(
				64,
				ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_DIGITS
			)
			->willReturn('StateToken');
		$this->session
			->expects($this->once())
			->method('set')
			->with('client.flow.state.token', 'StateToken');
		$this->session
			->expects($this->once())
			->method('get')
			->with('oauth.state')
			->willReturn('OauthStateToken');
		$this->defaults
			->expects($this->once())
			->method('getName')
			->willReturn('ExampleCloud');
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');

		$expected = new TemplateResponse(
			'core',
			'loginflow/authpicker',
			[
				'client' => 'Mac OS X Sync Client',
				'clientIdentifier' => '',
				'instanceName' => 'ExampleCloud',
				'urlGenerator' => $this->urlGenerator,
				'stateToken' => 'StateToken',
				'serverHost' => 'example.com',
				'oauthState' => 'OauthStateToken',
			],
			'guest'
		);
		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage());
	}

	public function testShowAuthPickerPageWithOauth() {
		$this->request
			->expects($this->at(0))
			->method('getHeader')
			->with('USER_AGENT')
			->willReturn('Mac OS X Sync Client');
		$client = new Client();
		$client->setName('My external service');
		$this->clientMapper
			->expects($this->once())
			->method('getByIdentifier')
			->with('MyClientIdentifier')
			->willReturn($client);
		$this->random
			->expects($this->once())
			->method('generate')
			->with(
				64,
				ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_DIGITS
			)
			->willReturn('StateToken');
		$this->session
			->expects($this->once())
			->method('set')
			->with('client.flow.state.token', 'StateToken');
		$this->session
			->expects($this->once())
			->method('get')
			->with('oauth.state')
			->willReturn('OauthStateToken');
		$this->defaults
			->expects($this->once())
			->method('getName')
			->willReturn('ExampleCloud');
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');

		$expected = new TemplateResponse(
			'core',
			'loginflow/authpicker',
			[
				'client' => 'My external service',
				'clientIdentifier' => 'MyClientIdentifier',
				'instanceName' => 'ExampleCloud',
				'urlGenerator' => $this->urlGenerator,
				'stateToken' => 'StateToken',
				'serverHost' => 'example.com',
				'oauthState' => 'OauthStateToken',
			],
			'guest'
		);
		$this->assertEquals($expected, $this->clientFlowLoginController->showAuthPickerPage('MyClientIdentifier'));
	}

	public function testRedirectPageWithInvalidToken() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('OtherToken');

		$expected = new TemplateResponse(
			'core',
			'403',
			[
				'file' => 'State token does not match',
			],
			'guest'
		);
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->redirectPage('MyStateToken'));
	}

	public function testRedirectPageWithoutToken() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn(null);

		$expected = new TemplateResponse(
			'core',
			'403',
			[
				'file' => 'State token does not match',
			],
			'guest'
		);
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->redirectPage('MyStateToken'));
	}

	public function testRedirectPage() {
		$this->session
			->expects($this->at(0))
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->at(1))
			->method('get')
			->with('oauth.state')
			->willReturn('MyOauthStateToken');

		$expected = new TemplateResponse(
			'core',
			'loginflow/redirect',
			[
				'urlGenerator' => $this->urlGenerator,
				'stateToken' => 'MyStateToken',
				'clientIdentifier' => 'Identifier',
				'oauthState' => 'MyOauthStateToken',
			],
			'guest'
		);
		$this->assertEquals($expected, $this->clientFlowLoginController->redirectPage('MyStateToken', 'Identifier'));
	}

	public function testGenerateAppPasswordWithInvalidToken() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('OtherToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');

		$expected = new TemplateResponse(
			'core',
			'403',
			[
				'file' => 'State token does not match',
			],
			'guest'
		);
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGenerateAppPasswordWithSessionNotAvailableException() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willThrowException(new SessionNotAvailableException());

		$expected = new Http\Response();
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGenerateAppPasswordWithInvalidTokenException() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willThrowException(new InvalidTokenException());

		$expected = new Http\Response();
		$expected->setStatus(Http::STATUS_FORBIDDEN);
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGeneratePasswordWithPassword() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willReturn('MyPassword');
		$this->random
			->expects($this->once())
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				'MyPassword',
				'unknown',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');

		$expected = new Http\RedirectResponse('nc://login/server:http://example.com&user:MyLoginName&password:MyGeneratedToken');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}

	public function testGeneratePasswordWithPasswordForOauthClient() {
		$this->session
			->expects($this->at(0))
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->at(1))
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->at(3))
			->method('get')
			->with('oauth.state')
			->willReturn('MyOauthState');
		$this->session
			->expects($this->at(4))
			->method('remove')
			->with('oauth.state');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willReturn('MyPassword');
		$this->random
			->expects($this->at(0))
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$this->random
			->expects($this->at(1))
			->method('generate')
			->with(128)
			->willReturn('MyAccessCode');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$token = $this->createMock(IToken::class);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				'MyPassword',
				'My OAuth client',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			)
			->willReturn($token);
		$client = new Client();
		$client->setName('My OAuth client');
		$client->setRedirectUri('https://example.com/redirect.php');
		$this->clientMapper
			->expects($this->once())
			->method('getByIdentifier')
			->with('MyClientIdentifier')
			->willReturn($client);

		$expected = new Http\RedirectResponse('https://example.com/redirect.php?state=MyOauthState&code=MyAccessCode');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken', 'MyClientIdentifier'));
	}

	public function testGeneratePasswordWithoutPassword() {
		$this->session
			->expects($this->once())
			->method('get')
			->with('client.flow.state.token')
			->willReturn('MyStateToken');
		$this->session
			->expects($this->once())
			->method('remove')
			->with('client.flow.state.token');
		$this->session
			->expects($this->once())
			->method('getId')
			->willReturn('SessionId');
		$myToken = $this->createMock(IToken::class);
		$myToken
			->expects($this->once())
			->method('getLoginName')
			->willReturn('MyLoginName');
		$this->tokenProvider
			->expects($this->once())
			->method('getToken')
			->with('SessionId')
			->willReturn($myToken);
		$this->tokenProvider
			->expects($this->once())
			->method('getPassword')
			->with($myToken, 'SessionId')
			->willThrowException(new PasswordlessTokenException());
		$this->random
			->expects($this->once())
			->method('generate')
			->with(72)
			->willReturn('MyGeneratedToken');
		$user = $this->createMock(IUser::class);
		$user
			->expects($this->once())
			->method('getUID')
			->willReturn('MyUid');
		$this->userSession
			->expects($this->once())
			->method('getUser')
			->willReturn($user);
		$this->tokenProvider
			->expects($this->once())
			->method('generateToken')
			->with(
				'MyGeneratedToken',
				'MyUid',
				'MyLoginName',
				null,
				'unknown',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
		$this->request
			->expects($this->once())
			->method('getServerProtocol')
			->willReturn('http');
		$this->request
			->expects($this->once())
			->method('getServerHost')
			->willReturn('example.com');

		$expected = new Http\RedirectResponse('nc://login/server:http://example.com&user:MyLoginName&password:MyGeneratedToken');
		$this->assertEquals($expected, $this->clientFlowLoginController->generateAppPassword('MyStateToken'));
	}
}
