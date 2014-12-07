# -*- coding: utf-8 -*-

from vdirsyncer.utils import expand_path
import subprocess
import os
import time
import pytest
import requests

owncloud_repo = os.path.dirname(__file__)
php_sh = os.path.abspath(os.path.join(owncloud_repo, 'php.sh'))


def wait():
    for i in range(5):
        try:
            requests.get('http://127.0.0.1:8080/')
        except Exception as e:
            # Don't know exact exception class, don't care.
            # Also, https://github.com/kennethreitz/requests/issues/2192
            if 'connection refused' not in str(e).lower():
                raise
            time.sleep(2 ** i)
        else:
            return True
    return False


class ServerMixin(object):
    storage_class = None
    wsgi_teardown = None

    @pytest.fixture(autouse=True)
    def setup(self, monkeypatch, xprocess):
        def preparefunc(cwd):
            return wait, ['sh', php_sh]

        xprocess.ensure('owncloud_server', preparefunc)
        subprocess.check_call([os.path.join(owncloud_repo, 'reset.sh')])

    @pytest.fixture
    def get_storage_args(self):
        def inner(collection='test'):
            base = 'http://127.0.0.1:8080'
            fileext = self.storage_class.fileext
            if fileext == '.vcf':
                dav_path = '/remote.php/carddav/addressbooks/asdf/'
            elif fileext == '.ics':
                dav_path = '/remote.php/caldav/calendars/asdf/'
            else:
                raise RuntimeError(fileext)

            if collection is not None:
                if fileext == '.ics':
                    requests.post(
                        base + '/index.php/apps/calendar/ajax/calendar/new.php',
                        data=dict(active=0, color='#ff0000', id='new',
                                  name=collection),
                        auth=('asdf', 'asdf')
                    )
                else:
                    requests.post(
                        base + '/index.php/apps/contacts/addressbook/local/add',
                        data=dict(displayname=collection, description=''),
                        auth=('asdf', 'asdf')
                    )

            return {'url': base + dav_path, 'collection': collection,
                    'username': 'asdf', 'password': 'asdf',
                    'unsafe_href_chars': ''}
        return inner
