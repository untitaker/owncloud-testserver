# -*- coding: utf-8 -*-

import os
import subprocess
import time

import lxml.html

import pytest

import requests

from vdirsyncer.utils import request


owncloud_repo = os.path.dirname(__file__)
php_sh = os.path.abspath(os.path.join(owncloud_repo, 'php.sh'))
base = 'http://127.0.0.1:8080'
username, password = ('asdf', 'asdf')


def wait():
    for i in range(5):
        try:
            requests.get(base + '/')
        except Exception as e:
            # Don't know exact exception class, don't care.
            # Also, https://github.com/kennethreitz/requests/issues/2192
            if 'connection refused' not in str(e).lower():
                raise
            time.sleep(2 ** i)
        else:
            return True
    return False


def get_request_token(session):
    r = request('GET', base + '/', session=session)
    tree = lxml.html.fromstring(r.content)
    return tree.find('head').attrib['data-requesttoken']


def create_address_book(name, token, session):
    r = request(
        'POST',
        base + '/index.php/apps/contacts/addressbook/local/add',
        data=dict(displayname=name, description=''),
        headers=dict(requesttoken=token),
        session=session
    ).json()
    assert r.get('uri', None) == name, r


def create_calendar(name, token, session):
    r = request(
        'POST',
        base + '/index.php/apps/calendar/ajax/calendar/new.php',
        data=dict(active=0, color='#ff0000', id='new', name=name),
        headers=dict(requesttoken=token),
        session=session
    ).json()
    assert r.get('status', None) == 'success', r


class ServerMixin(object):
    storage_class = None
    wsgi_teardown = None

    @pytest.fixture(autouse=True)
    def setup(self, monkeypatch, xprocess):
        def preparefunc(cwd):
            return wait, ['sh', php_sh]

        xprocess.ensure('owncloud_server', preparefunc)
        subprocess.check_call([os.path.join(owncloud_repo, 'reset.sh')])

    @pytest.fixture(scope='session')
    def owncloud_session(self):
        session = requests.session()
        session.auth = (username, password)
        return session

    @pytest.fixture(scope='session')
    def owncloud_csrf_token(self, owncloud_session):
        return get_request_token(owncloud_session)

    @pytest.fixture
    def get_storage_args(self, owncloud_session, owncloud_csrf_token):
        def inner(collection='test'):
            fileext = self.storage_class.fileext
            if fileext == '.vcf':
                dav_path = ('/remote.php/carddav/addressbooks/{}/'
                            .format(username))
            elif fileext == '.ics':
                dav_path = ('/remote.php/caldav/calendars/{}/'
                            .format(username))
            else:
                raise RuntimeError(fileext)

            if collection is not None:
                dav_path += collection + '/'
                if fileext == '.ics':
                    create_calendar(collection, owncloud_csrf_token,
                                    owncloud_session)
                else:
                    create_address_book(collection, owncloud_csrf_token,
                                        owncloud_session)

            return {'url': base + dav_path, 'collection': collection,
                    'username': username, 'password': password,
                    'unsafe_href_chars': ''}
        return inner
