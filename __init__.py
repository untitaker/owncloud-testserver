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
            url = 'http://127.0.0.1:8080'
            if self.storage_class.fileext == '.vcf':
                url += '/remote.php/carddav/addressbooks/asdf/'
            elif self.storage_class.fileext == '.ics':
                url += '/remote.php/caldav/calendars/asdf/'
            else:
                raise RuntimeError(self.storage_class.fileext)

            # the following collections are setup in ownCloud
            if collection is not None:
                assert collection in ('test', 'test1', 'test2', 'test3',
                                      'test4', 'test5', 'test6', 'test7',
                                      'test8', 'test9', 'test10')

            return {'url': url, 'collection': collection, 'username': 'asdf',
                    'password': 'asdf', 'unsafe_href_chars': ''}
        return inner
